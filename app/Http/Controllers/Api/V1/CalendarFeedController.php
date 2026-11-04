<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\CalendarEventStore;
use App\Models\CalendarReminder;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * GET /api/v1/calendar/feed?stores=03795-00001[,…]&from=YYYY-MM-DD&to=YYYY-MM-DD[&group=day]
 *
 * `group=day` (year view, all-stores grid): instead of `events`, `days` — how
 * many tasks each store has on each day, by status — for up to 371 days. Only
 * store tasks count: private ones and reminders belong to no store.
 *
 * Toolbox's part of the unified calendar (calendar-feed/v1):
 *   calendar_event  plain events in those stores
 *   task            one per task AND store, with that store's status
 *   reminder        the CALLER's own reminders, whatever the store (store = null)
 *
 * The caller's own PRIVATE events and tasks come too, like reminders: no store
 * (store = null, layer = mine). A private task is done, or pending / overdue.
 *
 * Task status in the calendar:
 *   open / in_progress / returned  → pending / in_progress, or overdue past the deadline
 *   submitted → done (extra.awaiting_review)   approved → done   cancelled → cancelled
 *
 * Who may see which stores is pizzasys's decision: the auth middleware forwards
 * the query string and the rule for this route reads `stores` from it (policy
 * "all"). We only refuse numbers we do not know, because pizzasys silently drops
 * those instead of refusing them. Times are UTC.
 */
class CalendarFeedController extends Controller
{
    private const MAX_STORES = 50;

    private const MAX_DAYS = 62;

    /** With group=day: a year — a 53-week fiscal year is 371 days. */
    private const MAX_DAYS_GROUPED = 371;

    /** The statuses a day counts; `info` and cancelled are not counted. */
    private const COUNTED = ['done' => 1, 'pending' => 1, 'overdue' => 1, 'missed' => 1, 'in_progress' => 1, 'scheduled' => 1];

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'stores' => ['required', 'string'],
            'from'   => ['required', 'date_format:Y-m-d'],
            'to'     => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'group'  => ['nullable', 'in:day'],
        ]);
        $grouped = ($data['group'] ?? null) === 'day';
        $maxDays = $grouped ? self::MAX_DAYS_GROUPED : self::MAX_DAYS;

        $numbers = array_values(array_unique(array_filter(array_map('trim', explode(',', $data['stores'])))));
        if ($numbers === [] || count($numbers) > self::MAX_STORES) {
            throw ValidationException::withMessages(['stores' => 'Send 1 to ' . self::MAX_STORES . ' store numbers.']);
        }
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > $maxDays) {
            throw ValidationException::withMessages(['to' => "Date range may not exceed {$maxDays} days."]);
        }

        // id => store_number
        $stores = Store::query()->whereIn('store_number', $numbers)->pluck('store_number', 'id');
        $unknown = array_values(array_diff($numbers, $stores->values()->all()));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['stores' => 'Unknown store: ' . implode(', ', $unknown) . '.']);
        }

        $me = (int) $request->user()->id;
        $events = array_merge(
            $this->storeEvents($stores, $data['from'], $data['to']),
            // no store: nothing for a store's day to count
            $grouped ? [] : $this->myPrivate($me, $data['from'], $data['to']),
            $grouped ? [] : $this->myReminders($me, $data['from'], $data['to']),
        );

        usort($events, fn ($a, $b) => [$a['date'], $a['start'] ?? '', $a['id']] <=> [$b['date'], $b['start'] ?? '', $b['id']]);

        $body = $grouped
            ? ['group' => 'day', 'days' => $this->days($events)]
            : ['events' => $events];

        return response()->json([
            'contract'     => 'calendar-feed/v1',
            'source'       => 'toolbox',
            'generated_at' => $this->utc(now()),
            'range'        => ['from' => $data['from'], 'to' => $data['to']],
            'stores'       => array_map(fn ($n) => ['number' => $n, 'timezone' => 'UTC'], $numbers),
            ...$body,
            'meta'         => ['count' => count($body['days'] ?? $body['events']), 'truncated' => false],
        ]);
    }

    /**
     * group=day: per store and day, how many things there are in each status.
     * A summary event adds its own counts; `info`, cancelled and store-less
     * events are not counted; days with nothing are left out.
     *
     * @return list<array{store: string, date: string, counts: array<string, int>}>
     */
    private function days(array $events): array
    {
        $days = [];
        foreach ($events as $e) {
            $add = isset($e['counts'])
                ? array_intersect_key($e['counts'], self::COUNTED)
                : (isset(self::COUNTED[$e['status']]) ? [$e['status'] => 1] : []);
            if ($e['store'] === null || array_sum($add) === 0) {
                continue;
            }
            $day = &$days[$e['date'] . '|' . $e['store']];
            $day ??= ['store' => $e['store'], 'date' => $e['date'], 'counts' => []];
            foreach (array_filter($add) as $status => $n) {
                $day['counts'][$status] = ($day['counts'][$status] ?? 0) + $n;
            }
            unset($day);
        }
        ksort($days);

        foreach ($days as &$day) {
            $day['counts']['total'] = array_sum($day['counts']);
        }
        unset($day);

        return array_values($days);
    }

    /** @return array<int, array<string, mixed>> */
    private function storeEvents($stores, string $from, string $to): array
    {
        $rows = CalendarEventStore::query()
            ->with(['event.task', 'event.creator:id,name', 'assignment'])
            ->whereIn('store_id', $stores->keys())
            ->whereHas('event', fn ($q) => $q
                ->whereIn('type', ['event', 'task'])
                ->where('scope', '!=', 'private')
                ->whereBetween('date', [$from, $to])
                // a cancelled plain event disappears; a cancelled task stays, as cancelled
                ->where(fn ($w) => $w->whereNull('cancelled_at')->orWhere('type', 'task')))
            ->get();

        return $rows->map(function (CalendarEventStore $row) use ($stores) {
            $event = $row->event;
            $store = $stores[$row->store_id];
            $date = $event->date->toDateString();
            $start = $event->start_time ? $date . 'T' . substr($event->start_time, 0, 5) . ':00Z' : null;
            $end = $event->end_time ? $date . 'T' . substr($event->end_time, 0, 5) . ':00Z' : null;
            $isTask = $event->type === 'task';

            $extra = ['category' => $event->category, 'priority' => $event->priority, 'scope' => $event->scope];
            if ($isTask) {
                $a = $row->assignment;
                $extra += [
                    'task_status'     => $a?->status,
                    'awaiting_review' => $a?->status === 'submitted',
                    'submitted_late'  => (bool) $a?->submitted_late,
                    'proof'           => $event->task?->proof,
                ];
            }

            return [
                'id'         => ($isTask ? "toolbox:task:{$event->id}:" : "toolbox:calendar_event:{$event->id}:") . $store,
                'type'       => $isTask ? 'task' : 'calendar_event',
                'layer'      => $isTask ? 'tasks' : 'company',
                'store'      => $store,
                'title'      => mb_substr($event->title, 0, 120),
                'all_day'    => $start === null,
                'date'       => $date,
                'date_end'   => null,
                'start'      => $start,
                'end'        => $start ? $end : null,
                'status'     => $isTask ? $this->taskStatus($row, $date) : 'info',
                'people'     => $event->creator ? [['kind' => 'user', 'id' => (int) $event->creator->id, 'name' => mb_substr($event->creator->name, 0, 60)]] : [],
                'link'       => '/toolbox/calendar/' . $event->id . '?' . http_build_query(['store' => $store]),
                'updated_at' => $this->utc(max($event->updated_at, $row->assignment?->updated_at ?? $event->updated_at)),
                'extra'      => array_filter($extra, fn ($v) => $v !== null),
            ];
        })->values()->all();
    }

    /**
     * The caller's own private events and tasks. No store: they are nobody else's.
     *
     * @return array<int, array<string, mixed>>
     */
    private function myPrivate(int $userId, string $from, string $to): array
    {
        return CalendarEvent::query()
            ->with('task')
            ->whereIn('type', ['event', 'task'])
            ->where('scope', 'private')
            ->where('created_by', $userId)
            ->whereBetween('date', [$from, $to])
            // as in the stores: a cancelled plain event disappears, a cancelled task stays
            ->where(fn ($w) => $w->whereNull('cancelled_at')->orWhere('type', 'task'))
            ->get()
            ->map(function (CalendarEvent $event) {
                $date = $event->date->toDateString();
                $start = $event->start_time ? $date . 'T' . substr($event->start_time, 0, 5) . ':00Z' : null;
                $isTask = $event->type === 'task';
                $doneAt = $event->task?->done_at;

                return [
                    'id'         => ($isTask ? 'toolbox:task:' : 'toolbox:calendar_event:') . $event->id,
                    'type'       => $isTask ? 'task' : 'calendar_event',
                    'layer'      => 'mine',
                    'store'      => null,
                    'title'      => mb_substr($event->title, 0, 120),
                    'all_day'    => $start === null,
                    'date'       => $date,
                    'date_end'   => null,
                    'start'      => $start,
                    'end'        => $start && $event->end_time ? $date . 'T' . substr($event->end_time, 0, 5) . ':00Z' : null,
                    'status'     => match (true) {
                        ! $isTask                     => 'info',
                        $event->isCancelled()         => 'cancelled',
                        $doneAt !== null              => 'done',
                        $this->deadline($event)->isPast() => 'overdue',
                        default                       => 'pending',
                    },
                    'people'     => [],
                    'link'       => '/toolbox/calendar/' . $event->id,
                    'updated_at' => $this->utc(max($event->updated_at, $event->task?->updated_at ?? $event->updated_at)),
                    'extra'      => array_filter([
                        'category' => $event->category,
                        'priority' => $event->priority,
                        'scope'    => 'private',
                        'done_at'  => $this->utc($doneAt),
                    ], fn ($v) => $v !== null),
                ];
            })
            ->all();
    }

    private function deadline(CalendarEvent $event): Carbon
    {
        $date = $event->date->toDateString();

        return $event->start_time ? Carbon::parse("{$date} {$event->start_time}", 'UTC') : Carbon::parse($date, 'UTC')->endOfDay();
    }

    /** @return array<int, array<string, mixed>> */
    private function myReminders(int $userId, string $from, string $to): array
    {
        return CalendarReminder::query()
            ->with('event:id,title,updated_at')
            ->where('user_id', $userId)
            ->where('status', '!=', 'cancelled')
            ->whereBetween('remind_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->get()
            ->map(fn (CalendarReminder $r) => [
                'id'         => 'toolbox:reminder:' . $r->calendar_event_id,
                'type'       => 'reminder',
                'layer'      => 'mine',
                'store'      => null,
                'title'      => 'Reminder: ' . mb_substr((string) $r->event?->title, 0, 110),
                'all_day'    => false,
                'date'       => $r->remind_at->copy()->utc()->toDateString(),
                'date_end'   => null,
                'start'      => $this->utc($r->remind_at),
                'end'        => null,
                'status'     => match ($r->status) {
                    'scheduled' => 'scheduled',
                    'done'      => 'done',
                    default     => 'info', // sent
                },
                'people'     => [],
                'link'       => $r->target_link ?? '/toolbox/calendar/' . $r->calendar_event_id,
                'updated_at' => $this->utc($r->updated_at),
                'extra'      => array_filter([
                    'reminder_status' => $r->status,
                    'target_ref'      => $r->target_ref,
                    'target_title'    => $r->target_title,
                ], fn ($v) => $v !== null),
            ])
            ->all();
    }

    private function taskStatus(CalendarEventStore $row, string $date): string
    {
        $status = $row->assignment?->status ?? 'open';
        $time = $row->event->start_time;
        $deadline = $time ? Carbon::parse("{$date} {$time}", 'UTC') : Carbon::parse($date, 'UTC')->endOfDay();

        return match (true) {
            $status === 'cancelled'                        => 'cancelled',
            in_array($status, ['submitted', 'approved'])   => 'done',
            $deadline->isPast()                            => 'overdue',
            $status === 'in_progress'                      => 'in_progress',
            default                                        => 'pending',
        };
    }

    private function utc($at): ?string
    {
        return $at ? Carbon::parse($at, 'UTC')->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }
}
