<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CalendarEventStore;
use App\Models\CalendarReminder;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * GET /api/v1/calendar/feed?stores=03795-00001[,…]&from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * Toolbox's part of the unified calendar (calendar-feed/v1):
 *   calendar_event  plain events in those stores
 *   task            one per task AND store, with that store's status
 *   reminder        the CALLER's own reminders, whatever the store (store = null)
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

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'stores' => ['required', 'string'],
            'from'   => ['required', 'date_format:Y-m-d'],
            'to'     => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $numbers = array_values(array_unique(array_filter(array_map('trim', explode(',', $data['stores'])))));
        if ($numbers === [] || count($numbers) > self::MAX_STORES) {
            throw ValidationException::withMessages(['stores' => 'Send 1 to ' . self::MAX_STORES . ' store numbers.']);
        }
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Date range may not exceed ' . self::MAX_DAYS . ' days.']);
        }

        // id => store_number
        $stores = Store::query()->whereIn('store_number', $numbers)->pluck('store_number', 'id');
        $unknown = array_values(array_diff($numbers, $stores->values()->all()));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['stores' => 'Unknown store: ' . implode(', ', $unknown) . '.']);
        }

        $events = array_merge(
            $this->storeEvents($stores, $data['from'], $data['to']),
            $this->myReminders((int) $request->user()->id, $data['from'], $data['to']),
        );

        usort($events, fn ($a, $b) => [$a['date'], $a['start'] ?? '', $a['id']] <=> [$b['date'], $b['start'] ?? '', $b['id']]);

        return response()->json([
            'contract'     => 'calendar-feed/v1',
            'source'       => 'toolbox',
            'generated_at' => $this->utc(now()),
            'range'        => ['from' => $data['from'], 'to' => $data['to']],
            'stores'       => array_map(fn ($n) => ['number' => $n, 'timezone' => 'UTC'], $numbers),
            'events'       => $events,
            'meta'         => ['count' => count($events), 'truncated' => false],
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function storeEvents($stores, string $from, string $to): array
    {
        $rows = CalendarEventStore::query()
            ->with(['event.task', 'event.creator:id,name', 'assignment'])
            ->whereIn('store_id', $stores->keys())
            ->whereHas('event', fn ($q) => $q
                ->whereIn('type', ['event', 'task'])
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

            $extra = ['category' => $event->category, 'priority' => $event->priority];
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
                'title'      => '🔔 ' . mb_substr((string) $r->event?->title, 0, 115),
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
