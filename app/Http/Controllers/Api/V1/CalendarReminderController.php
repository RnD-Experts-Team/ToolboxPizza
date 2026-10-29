<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CalendarReminder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The caller's own reminders. Created through POST /v1/calendar/events with
 * type = reminder; sent to the bell by calendar:dispatch-reminders.
 *
 *   GET  /v1/calendar/reminders              upcoming and just-sent
 *   POST /v1/calendar/reminders/{id}/snooze  { minutes }
 *   POST /v1/calendar/reminders/{id}/done
 *   POST /v1/calendar/reminders/{id}/cancel
 *
 * {id} is the calendar event id. Someone else's reminder is a 404, never a 403:
 * whether it exists is nobody else's business.
 */
class CalendarReminderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $reminders = CalendarReminder::query()
            ->with('event:id,title,description,link')
            ->where('user_id', $request->user()->id)
            ->whereIn('status', ['scheduled', 'sent'])
            ->orderBy('remind_at')
            ->limit(200)
            ->get()
            ->map(fn (CalendarReminder $r) => $this->present($r));

        return response()->json(['data' => $reminders]);
    }

    public function snooze(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['minutes' => ['required', 'integer', 'min:1', 'max:1440']]);
        $reminder = $this->mine($request, $id);
        $this->from($reminder, ['scheduled', 'sent']);

        $reminder->update([
            'status'       => 'scheduled',
            'remind_at'    => now()->addMinutes((int) $data['minutes']),
            'sent_at'      => null,
            'snooze_count' => $reminder->snooze_count + 1,
        ]);
        $reminder->event()->update(['date' => $reminder->remind_at->toDateString(), 'start_time' => $reminder->remind_at->format('H:i:s')]);

        return response()->json(['data' => $this->present($reminder->fresh('event'))]);
    }

    public function done(Request $request, int $id): JsonResponse
    {
        $reminder = $this->mine($request, $id);
        $this->from($reminder, ['scheduled', 'sent']);
        $reminder->update(['status' => 'done']);

        return response()->json(['data' => $this->present($reminder->fresh('event'))]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $reminder = $this->mine($request, $id);
        $this->from($reminder, ['scheduled', 'sent']);
        $reminder->update(['status' => 'cancelled']);

        return response()->json(['data' => $this->present($reminder->fresh('event'))]);
    }

    private function mine(Request $request, int $eventId): CalendarReminder
    {
        return CalendarReminder::query()
            ->with('event')
            ->where('calendar_event_id', $eventId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }

    private function from(CalendarReminder $reminder, array $states): void
    {
        if (! in_array($reminder->status, $states, true)) {
            throw ValidationException::withMessages(['status' => "This reminder is already '{$reminder->status}'."]);
        }
    }

    /** @return array<string, mixed> */
    private function present(CalendarReminder $r): array
    {
        return [
            'id'           => (int) $r->calendar_event_id,
            'title'        => $r->event?->title,
            'description'  => $r->event?->description,
            'remind_at'    => $r->remind_at->toIso8601String(),
            'status'       => $r->status,
            'sent_at'      => $r->sent_at?->toIso8601String(),
            'snooze_count' => $r->snooze_count,
            'target_ref'   => $r->target_ref,
            'target_title' => $r->target_title,
            'target_link'  => $r->target_link,
        ];
    }
}
