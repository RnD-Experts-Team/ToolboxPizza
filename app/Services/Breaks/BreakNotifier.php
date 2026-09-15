<?php

namespace App\Services\Breaks;

use App\Enums\MilestoneKind;
use App\Jobs\PublishOutboxEventJob;
use App\Models\BreakMilestoneFiring;
use App\Models\ToolboxOutboxEvent;
use App\Models\User;
use App\Services\ToolboxEvents\ToolboxEventFactory;
use App\Services\ToolboxEvents\ToolboxOutboxService;

/**
 * The seam to NotificationsPizza.
 *
 * There is no HTTP endpoint anywhere to create a notification: a service writes
 * a CloudEvents envelope to its own outbox inside the domain transaction, and
 * NotificationsPizza consumes it from NATS, stores an InAppNotification and
 * broadcasts over ITS Reverb. Nothing about Reverb belongs in this service.
 *
 * OFF by default. When disabled, nothing here writes a row - the crossing is
 * still recorded in break_milestone_firings, it just goes nowhere. Turning it
 * on must be paired with scheduling breaks:evaluate-milestones; see
 * routes/console.php for why.
 */
class BreakNotifier
{
    public function __construct(
        private readonly ToolboxEventFactory $events,
        private readonly ToolboxOutboxService $outbox,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('toolbox.notifications.enabled');
    }

    /**
     * Announce a firing, and stamp the outbox row back onto it.
     *
     * @param  array<string, mixed>  $totals  counted/allowance/remaining/over, in minutes
     */
    public function announce(User $user, BreakMilestoneFiring $firing, array $totals): ?ToolboxOutboxEvent
    {
        if (! $this->enabled()) {
            return null;
        }

        $data = [
            'user_id' => $user->id,
            'work_date' => $firing->work_date->toDateString(),
            'kind' => $firing->kind->value,
            'threshold_minutes' => $firing->threshold_minutes,
            'counted_minutes' => $totals['counted_minutes'],
            'allowance_minutes' => $totals['allowance_minutes'],
            'remaining_minutes' => $totals['remaining_minutes'],
            'over_minutes' => $totals['over_minutes'],
            'crossed_at' => $firing->crossed_at->toIso8601String(),
            'noticed_at' => $firing->noticed_at->toIso8601String(),
        ];

        // Our own domain event, for anyone who wants to observe breaks without
        // being a notification channel.
        $domainSubject = $firing->kind === MilestoneKind::Allowance
            ? 'toolbox.v1.break.allowance_exceeded'
            : 'toolbox.v1.break.milestone_reached';

        $this->record($domainSubject, $this->events->make($domainSubject, $data));

        // The notification envelope NotificationsPizza actually consumes.
        $row = $this->record(
            'notifications.v1.notification.send',
            $this->events->make('notifications.v1.notification.send', $this->notificationPayload($user, $firing, $totals)),
        );

        $firing->forceFill(['outbox_event_id' => $row->id])->save();

        return $row;
    }

    private function record(string $subject, array $envelope): ToolboxOutboxEvent
    {
        $row = $this->outbox->record($subject, $envelope);

        // The queue is the fast path; outbox:publish-pending is the guarantee.
        //
        // afterCommit() is load-bearing: this runs inside BreakWriteService's
        // transaction, and config/queue.php has after_commit => false on every
        // connection (as the siblings do). Without it a worker can claim the job
        // before the row commits, find nothing, and return silently - the
        // sweeper would still recover it, but every notification would be up to
        // five minutes late.
        PublishOutboxEventJob::dispatch($row->id)->afterCommit();

        return $row;
    }

    /**
     * @param  array<string, mixed>  $totals
     * @return array<string, mixed>
     */
    private function notificationPayload(User $user, BreakMilestoneFiring $firing, array $totals): array
    {
        $workDate = $firing->work_date->toDateString();
        $actionUrl = rtrim((string) config('toolbox.notifications.action_url'), '/')."?date={$workDate}";

        if ($firing->kind === MilestoneKind::Allowance) {
            $title = 'Daily break allowance used up';
            // "nothing is blocked" is deliberate: the soft limit is a product
            // decision, and this is the only place the user will ever read it.
            $body = sprintf(
                "You've used %d of your %d daily break minutes - %d over. Breaks keep running; nothing is blocked.",
                $totals['counted_minutes'],
                $totals['allowance_minutes'],
                $totals['over_minutes'],
            );
            $type = 'break_allowance_exceeded';
        } else {
            $title = sprintf('%d minutes of break used', $firing->threshold_minutes);
            $body = sprintf(
                "You've used %d of your %d daily break minutes. %d minutes left.",
                $totals['counted_minutes'],
                $totals['allowance_minutes'],
                $totals['remaining_minutes'],
            );
            $type = 'break_milestone_reached';
        }

        return [
            'channels' => ['web'],
            'users' => [[
                'id' => $user->id,
                'data' => [
                    'type' => $type,
                    'title' => $title,
                    'body' => $body,
                    'action_url' => $actionUrl,
                ],
            ]],
        ];
    }
}
