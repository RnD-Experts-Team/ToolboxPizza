<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\CalendarEventStore;
use App\Models\CalendarTask;
use App\Models\CalendarTaskAssignment;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The calendar's own events, tasks and reminders.
 *
 *   POST /v1/calendar/events                         create (type = event | task | reminder)
 *   GET  /v1/calendar/events/{id}                    details
 *   POST /v1/calendar/events/{id}/cancel             creator only
 *   POST /v1/calendar/tasks/{id}/{action}?stores=X   start | submit | approve | return, in store X
 *   POST /v1/calendar/tasks/{id}/{done|reopen}       a private task (no stores)
 *
 * `scope` says who it is for: `custom` the stores sent (anyone, as before),
 * `public` every store, `private` only its creator — no stores at all.
 *
 * Who may call what is pizzasys's decision, as everywhere in this service. The
 * stores travel as `stores` (body on create, query on task actions) so the rule
 * can check every one of them. What only this service can know is checked here:
 * a reminder or a private event or task is its creator's alone, only the creator
 * reviews a task, and only `administer calendar` (the super-admin) may make a
 * public or private one.
 *
 * Dates and times are UTC.
 */
class CalendarEventController extends Controller
{
    private const MAX_STORES = 50;

    /** Global permission for public and private events and tasks; the super-admin holds it. */
    private const ADMIN_PERMISSION = 'administer calendar';

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'        => ['required', Rule::in(CalendarEvent::TYPES)],
            'title'       => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category'    => ['nullable', 'string', 'max:40'],
            'priority'    => ['nullable', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'link'        => ['nullable', 'string', 'max:255'],

            // events and tasks: stores (a list or "a,b") only for a custom scope
            'scope'       => ['exclude_if:type,reminder', 'nullable', Rule::in(CalendarEvent::SCOPES)],
            'stores'      => ['exclude_if:type,reminder', 'required_if:scope,custom', 'required_without:scope', 'prohibited_if:scope,public', 'prohibited_if:scope,private'],
            'date'        => ['exclude_if:type,reminder', 'required', 'date_format:Y-m-d'],
            'start_time'  => ['exclude_if:type,reminder', 'nullable', 'date_format:H:i'],
            'end_time'    => ['exclude_if:type,reminder', 'nullable', 'date_format:H:i', 'after:start_time'],

            // tasks only; left out = no proof, reviewed by the creator
            'task'                 => ['prohibited_unless:type,task', 'nullable', 'array'],
            'task.proof'           => ['nullable', Rule::in(CalendarTask::PROOFS)],
            'task.requires_review' => ['nullable', 'boolean'],

            // reminders only: exactly one of in_minutes / remind_at
            'reminder'              => ['required_if:type,reminder', 'prohibited_unless:type,reminder', 'array'],
            'reminder.in_minutes'   => ['exclude_unless:type,reminder', 'required_without:reminder.remind_at', 'prohibits:reminder.remind_at', 'integer', 'min:1', 'max:525600'],
            'reminder.remind_at'    => ['exclude_unless:type,reminder', 'required_without:reminder.in_minutes', 'date', 'after:now', 'before:+1 year'],
            'reminder.target_ref'   => ['nullable', 'string', 'max:120'],
            'reminder.target_title' => ['nullable', 'string', 'max:120'],
            'reminder.target_link'  => ['nullable', 'string', 'max:255'],
        ]);

        $userId = (int) $request->user()->id;

        if ($data['type'] === 'reminder') {
            return response()->json(['data' => $this->createReminder($data, $userId)], 201);
        }

        $scope = $data['scope'] ?? 'custom';
        $private = $scope === 'private';
        abort_if($scope !== 'custom' && ! $this->isCalendarAdmin($request), 403,
            "Only a calendar administrator can create a {$scope} {$data['type']}.");

        $storeIds = match ($scope) {
            'public'  => $this->allStores(),
            'private' => [],
            default   => $this->resolveStores($data['stores']),
        };

        if ($data['type'] === 'task') {
            $deadline = $this->deadline($data['date'], $data['start_time'] ?? null);
            if ($deadline->isPast()) {
                throw ValidationException::withMessages(['date' => 'The deadline is already in the past.']);
            }
        }

        $event = DB::transaction(function () use ($data, $userId, $storeIds, $scope, $private) {
            $event = CalendarEvent::query()->create([
                'type'        => $data['type'],
                'title'       => $data['title'],
                'description' => $data['description'] ?? null,
                'category'    => $data['category'] ?? null,
                'priority'    => $data['priority'] ?? 'medium',
                'scope'       => $scope,
                'date'        => $data['date'],
                'start_time'  => $data['start_time'] ?? null,
                'end_time'    => $data['end_time'] ?? null,
                'link'        => $data['link'] ?? null,
                'created_by'  => $userId,
            ]);

            if ($event->type === 'task') {
                // A private task is only done or not done: no proof, nobody to review it.
                $event->task()->create([
                    'proof'           => $private ? 'none' : ($data['task']['proof'] ?? 'none'),
                    'requires_review' => $private ? false : ($data['task']['requires_review'] ?? true),
                ]);
            }

            foreach ($storeIds as $storeId) {
                $row = $event->stores()->create(['store_id' => $storeId]);
                if ($event->type === 'task') {
                    $row->assignment()->create(['status' => 'open']);
                }
            }

            return $event;
        });

        return response()->json(['data' => $this->present($event->fresh())], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->present($this->find($request, $id))]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $event = $this->find($request, $id);
        abort_unless((int) $event->created_by === (int) $request->user()->id, 403, 'Only the creator can cancel this.');
        abort_if($event->isCancelled(), 422, 'Already cancelled.');

        DB::transaction(function () use ($event) {
            $event->update(['cancelled_at' => now()]);

            // Tasks: every store not already finished. Reminders: never fire.
            CalendarTaskAssignment::query()
                ->whereIn('calendar_event_store_id', $event->stores()->pluck('id'))
                ->whereIn('status', [...CalendarTaskAssignment::OPEN, 'submitted'])
                ->update(['status' => 'cancelled']);

            $event->reminder()->whereIn('status', ['scheduled', 'sent'])->update(['status' => 'cancelled']);
        });

        return response()->json(['data' => $this->present($event->fresh())]);
    }

    /** POST /v1/calendar/tasks/{id}/{action}?stores=03795-00001 — or {done|reopen} for a private task */
    public function taskAction(Request $request, int $id, string $action): JsonResponse
    {
        abort_unless(in_array($action, ['start', 'submit', 'approve', 'return', 'done', 'reopen'], true), 404);

        $event = CalendarEvent::query()->with('task')->where('type', 'task')->findOrFail($id);
        abort_if($event->isPrivate() && (int) $event->created_by !== (int) $request->user()->id, 404);
        abort_if($event->isCancelled(), 422, 'This task was cancelled.');

        // A private task has no stores: it is done, or it is not.
        if ($event->isPrivate() !== in_array($action, ['done', 'reopen'], true)) {
            throw ValidationException::withMessages(['action' => $event->isPrivate()
                ? 'A private task is only marked done or reopened.'
                : "'{$action}' is only for a private task."]);
        }
        if ($event->isPrivate()) {
            $isDone = $event->task->done_at !== null;
            if ($isDone === ($action === 'done')) {
                throw ValidationException::withMessages(['status' => $isDone ? 'Already done.' : 'It is not done.']);
            }
            $event->task->update(['done_at' => $action === 'done' ? now() : null]);

            return response()->json(['data' => $this->present($event->fresh())]);
        }

        $storeIds = $this->resolveStores($request->query('stores', ''));
        abort_unless(count($storeIds) === 1, 422, 'Send exactly one store in `stores`.');

        $assignment = CalendarTaskAssignment::query()
            ->whereHas('eventStore', fn ($q) => $q->where('calendar_event_id', $event->id)->where('store_id', $storeIds[0]))
            ->firstOrFail();

        $userId = (int) $request->user()->id;
        $isCreator = (int) $event->created_by === $userId;

        switch ($action) {
            case 'start':
                $this->transition($assignment, ['open', 'returned'], ['status' => 'in_progress', 'started_at' => now()]);
                break;

            case 'submit':
                $data = $request->validate(['note' => [$event->task->proof === 'note' ? 'required' : 'nullable', 'string', 'max:2000']]);
                $this->transition($assignment, CalendarTaskAssignment::OPEN, [
                    // No review wanted: done the moment it is submitted.
                    'status'          => $event->task->requires_review ? 'submitted' : 'approved',
                    'submitted_at'    => now(),
                    'submitted_by'    => $userId,
                    'submitted_late'  => $this->deadline($event->date->toDateString(), $event->start_time)->isPast(),
                    'completion_note' => $data['note'] ?? null,
                    'return_reason'   => null,
                ]);
                break;

            case 'approve':
                abort_unless($isCreator, 403, 'Only the creator reviews this task.');
                $this->transition($assignment, ['submitted'], ['status' => 'approved', 'reviewed_at' => now(), 'reviewed_by' => $userId]);
                break;

            case 'return':
                abort_unless($isCreator, 403, 'Only the creator reviews this task.');
                $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
                $this->transition($assignment, ['submitted'], [
                    'status' => 'returned', 'reviewed_at' => now(), 'reviewed_by' => $userId, 'return_reason' => $data['reason'],
                ]);
                break;
        }

        return response()->json(['data' => $this->present($event->fresh())]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function createReminder(array $data, int $userId): array
    {
        $at = isset($data['reminder']['in_minutes'])
            ? now()->addMinutes((int) $data['reminder']['in_minutes'])
            : Carbon::parse($data['reminder']['remind_at'])->utc();

        $event = DB::transaction(function () use ($data, $userId, $at) {
            $event = CalendarEvent::query()->create([
                'type'        => 'reminder',
                'title'       => $data['title'],
                'description' => $data['description'] ?? null,
                'priority'    => $data['priority'] ?? 'medium',
                'scope'       => 'private', // a reminder is its owner's alone
                'date'        => $at->toDateString(),
                'start_time'  => $at->format('H:i:s'),
                'link'        => $data['link'] ?? null,
                'created_by'  => $userId,
            ]);

            $event->reminder()->create([
                'user_id'      => $userId,
                'remind_at'    => $at,
                'target_ref'   => $data['reminder']['target_ref'] ?? null,
                'target_title' => $data['reminder']['target_title'] ?? null,
                'target_link'  => $data['reminder']['target_link'] ?? null,
                'status'       => 'scheduled',
            ]);

            return $event;
        });

        return $this->present($event->fresh());
    }

    /** A reminder, or a private event or task, is its owner's alone: anyone else gets 404, as if it did not exist. */
    private function find(Request $request, int $id): CalendarEvent
    {
        $event = CalendarEvent::query()->with('reminder')->findOrFail($id);
        $me = (int) $request->user()->id;

        abort_if(
            ($event->type === 'reminder' && (int) $event->reminder?->user_id !== $me)
                || ($event->isPrivate() && (int) $event->created_by !== $me),
            404
        );

        return $event;
    }

    /** Holds `administer calendar` (from pizzasys, like `administer tickets`). */
    private function isCalendarAdmin(Request $request): bool
    {
        return in_array(self::ADMIN_PERMISSION, (array) $request->attributes->get('authz_permissions', []), true);
    }

    /**
     * A public event's stores: every store this service knows, at this moment —
     * a store opened later does not get it.
     *
     * @return array<int, int>
     */
    private function allStores(): array
    {
        return Store::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Store numbers → store ids. Unknown numbers are refused: pizzasys drops what
     * it cannot resolve instead of refusing it, so they would otherwise pass.
     *
     * @return array<int, int>
     */
    private function resolveStores(mixed $value): array
    {
        $numbers = is_array($value) ? $value : explode(',', (string) $value);
        $numbers = array_values(array_unique(array_filter(array_map(fn ($v) => trim((string) $v), $numbers))));

        if ($numbers === [] || count($numbers) > self::MAX_STORES) {
            throw ValidationException::withMessages(['stores' => 'Send 1 to ' . self::MAX_STORES . ' store numbers.']);
        }

        $ids = Store::query()->whereIn('store_number', $numbers)->pluck('id', 'store_number');
        $unknown = array_values(array_diff($numbers, $ids->keys()->all()));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['stores' => 'Unknown store: ' . implode(', ', $unknown) . '.']);
        }

        return $ids->values()->map(fn ($id) => (int) $id)->all();
    }

    /** A task's deadline: its date and time, or the end of that day. UTC. */
    private function deadline(string $date, ?string $time): Carbon
    {
        return $time
            ? Carbon::parse("{$date} {$time}", 'UTC')
            : Carbon::parse($date, 'UTC')->endOfDay();
    }

    /** Move an assignment only from the states it may leave, or refuse. */
    private function transition(CalendarTaskAssignment $assignment, array $from, array $changes): void
    {
        if (! in_array($assignment->status, $from, true)) {
            throw ValidationException::withMessages([
                'status' => "Cannot do that while the task is '{$assignment->status}'.",
            ]);
        }

        $assignment->update($changes);
    }

    /** @return array<string, mixed> */
    private function present(CalendarEvent $event): array
    {
        $event->loadMissing(['task', 'reminder', 'stores.store:id,store_number', 'stores.assignment']);

        return [
            'id'           => $event->id,
            'type'         => $event->type,
            'title'        => $event->title,
            'description'  => $event->description,
            'category'     => $event->category,
            'priority'     => $event->priority,
            'scope'        => $event->scope ?? 'custom',
            'date'         => $event->date->toDateString(),
            'start_time'   => $event->start_time ? substr($event->start_time, 0, 5) : null,
            'end_time'     => $event->end_time ? substr($event->end_time, 0, 5) : null,
            'link'         => $event->link,
            'created_by'   => (int) $event->created_by,
            'cancelled_at' => $event->cancelled_at?->toIso8601String(),
            'task'         => $event->task ? [
                'proof'           => $event->task->proof,
                'requires_review' => $event->task->requires_review,
                'done_at'         => $event->task->done_at?->toIso8601String(), // private tasks only
            ] : null,
            'reminder'     => $event->reminder ? [
                'remind_at'    => $event->reminder->remind_at->toIso8601String(),
                'status'       => $event->reminder->status,
                'target_ref'   => $event->reminder->target_ref,
                'target_title' => $event->reminder->target_title,
                'target_link'  => $event->reminder->target_link,
            ] : null,
            'stores'       => $event->stores->map(fn (CalendarEventStore $s) => [
                'store'      => $s->store?->store_number,
                'assignment' => $s->assignment ? [
                    'status'          => $s->assignment->status,
                    'submitted_at'    => $s->assignment->submitted_at?->toIso8601String(),
                    'submitted_late'  => $s->assignment->submitted_late,
                    'completion_note' => $s->assignment->completion_note,
                    'return_reason'   => $s->assignment->return_reason,
                ] : null,
            ])->values()->all(),
        ];
    }
}
