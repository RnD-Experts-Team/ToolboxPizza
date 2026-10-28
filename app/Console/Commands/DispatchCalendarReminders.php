<?php

namespace App\Console\Commands;

use App\Models\CalendarReminder;
use App\Services\ToolboxEvents\ToolboxOutboxService;
use Illuminate\Console\Command;

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
                        'title'      => '🔔 ' . $reminder->event->title,
                        'body'       => $reminder->target_title ?? ($reminder->event->description ?? ''),
                        'action_url' => $reminder->target_link ?? $reminder->event->link ?? '/toolbox/calendar',
                    ]);
                    $sent++;
                }
            });

        $this->info("Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
