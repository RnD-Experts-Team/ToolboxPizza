<?php

namespace App\Console\Commands;

use App\Models\CalendarReminder;
use App\Services\ToolboxEvents\ToolboxOutboxService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Sends every reminder whose time has come to its owner's bell.
 *
 * Scheduled every minute (routes/console.php), so a reminder arrives within a
 * minute of `remind_at`. Each one is CLAIMED first — an UPDATE that only
 * succeeds while it is still `scheduled` — and only the run that claimed it
 * writes the notification, so two overlapping runs can never send it twice.
 */
class DispatchCalendarReminders extends Command
{
    protected $signature = 'calendar:dispatch-reminders';

    protected $description = 'Send due calendar reminders to their owners';

    public function handle(ToolboxOutboxService $outbox): int
    {
        $sent = 0;

        CalendarReminder::query()
            ->with('event:id,title,description,link,cancelled_at')
            ->where('status', 'scheduled')
            ->where('remind_at', '<=', now())
            ->orderBy('id')
            ->chunkById(200, function ($reminders) use ($outbox, &$sent) {
                foreach ($reminders as $reminder) {
                    $claimed = CalendarReminder::query()
                        ->whereKey($reminder->id)
                        ->where('status', 'scheduled')
                        ->update(['status' => 'sent', 'sent_at' => now()]);

                    if ($claimed === 0 || $reminder->event?->cancelled_at !== null) {
                        continue;
                    }

                    $outbox->notify([(int) $reminder->user_id], [
                        'type'       => 'calendar_reminder',
                        // Worded like every other bell entry: what it is, then the detail — no emoji.
                        'title'      => 'Reminder: ' . Str::limit($reminder->event->title, 80),
                        'body'       => $this->body($reminder),
                        'action_url' => $reminder->target_link ?? $reminder->event->link ?? '/toolbox/calendar',
                    ]);
                    $sent++;
                }
            });

        $this->info("Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }

    /**
     * The detail under the title: what the reminder points at, else its note,
     * else when it was due — never the title again (a reminder made from a
     * calendar event usually has that event's title as both).
     */
    private function body(CalendarReminder $reminder): string
    {
        $title = trim($reminder->event->title);

        foreach ([$reminder->target_title, $reminder->event->description] as $detail) {
            if (filled($detail) && strcasecmp(trim($detail), $title) !== 0) {
                return Str::limit(trim($detail), 140);
            }
        }

        return 'You asked to be reminded at ' . $reminder->remind_at->utc()->format('H:i') . ' UTC.';
    }
}
