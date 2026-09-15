<?php

namespace App\Services\Breaks;

use App\Jobs\PublishOutboxEventJob;
use App\Models\BreakEntry;
use App\Models\BreakMilestoneFiring;
use App\Models\ToolboxOutboxEvent;
use App\Models\User;
use App\Services\ToolboxEvents\ToolboxEventFactory;
use App\Services\ToolboxEvents\ToolboxOutboxService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Live break state, pushed to the user's socket.
 *
 * It rides NotificationsPizza's Reverb on the private-users.{id} channel the
 * dashboard already subscribes to - one socket per browser session, no second
 * Echo connection, no Reverb process here. The transport is
 * `notifications.v1.broadcast.send`, the transient path that broadcasts WITHOUT
 * writing an InAppNotification row, so a moving timer never fills the bell.
 *
 * TWO THINGS THIS DELIBERATELY DOES NOT DO:
 *
 * 1. It does not broadcast the ticking clock. Only state CHANGES go out -
 *    started, stopped, edited, deleted, a milestone crossed. Between them the
 *    client ticks locally from `started_at`, which is why every payload carries
 *    the entry and the day totals rather than a countdown. Pushing per-second
 *    would be a firehose for a number the client can compute itself.
 *
 * 2. It does not publish straight to NATS. Broadcasts go through the same
 *    transactional outbox as everything else, so one can never fire for a write
 *    that rolled back - "you started a break" for a failed transaction is worse
 *    than one arriving a beat late. The cost is that the queue worker and
 *    nats:consume both have to be running, or live updates stop until the
 *    five-minute sweeper catches up.
 */
class BreakBroadcaster
{
    public const STARTED = 'break.started';

    public const STOPPED = 'break.stopped';

    public const UPDATED = 'break.updated';

    public const DELETED = 'break.deleted';

    public const MILESTONE = 'break.milestone';

    public function __construct(
        private readonly ToolboxEventFactory $events,
        private readonly ToolboxOutboxService $outbox,
        private readonly BreakQueryService $reader,
        private readonly BreakDayTotals $totals,
    ) {}

    /**
     * Separate from toolbox.notifications.enabled on purpose: live sync and
     * milestone toasts are different products, wanted independently, and they
     * fail independently.
     */
    public function enabled(): bool
    {
        return (bool) config('toolbox.realtime.enabled');
    }

    /**
     * A break was started, stopped or edited.
     */
    public function entryChanged(
        User $user,
        BreakEntry $entry,
        string $event,
        ?CarbonInterface $asOf = null,
        ?string $previousWorkDate = null,
    ): ?ToolboxOutboxEvent {
        if (! $this->enabled()) {
            return null;
        }

        $asOf = $asOf === null ? CarbonImmutable::now() : CarbonImmutable::parse($asOf);
        $workDate = $entry->work_date->toDateString();

        $data = [
            'break_id' => $entry->id,
            'work_date' => $workDate,
            'entry' => $this->reader->present($entry->loadMissing(['breakType', 'notes.creator']), $asOf),
            'totals' => $this->totals->forDay($user, $workDate, $asOf),
        ];

        // An edit that moves started_at across the cutoff moves the entry to a
        // different work day. A client showing the old day has to drop it and
        // restate that day's figures, so both are carried in the one message
        // rather than leaving it to guess or refetch.
        if ($previousWorkDate !== null && $previousWorkDate !== $workDate) {
            $data['previous_work_date'] = $previousWorkDate;
            $data['previous_totals'] = $this->totals->forDay($user, $previousWorkDate, $asOf);
        }

        return $this->push($user, $event, $data);
    }

    /**
     * A break was deleted. The entry is already gone, so the client is told
     * which id to drop plus the totals that replace it.
     */
    public function entryDeleted(User $user, int $breakId, string $workDate, ?CarbonInterface $asOf = null): ?ToolboxOutboxEvent
    {
        if (! $this->enabled()) {
            return null;
        }

        $asOf = $asOf === null ? CarbonImmutable::now() : CarbonImmutable::parse($asOf);

        return $this->push($user, self::DELETED, [
            'break_id' => $breakId,
            'work_date' => $workDate,
            'entry' => null,
            'totals' => $this->totals->forDay($user, $workDate, $asOf),
        ]);
    }

    /**
     * A threshold was crossed.
     *
     * Separate from the notification BreakNotifier may also emit: this one
     * arrives instantly on the socket so the UI can react, while the
     * notification is the durable record in the bell. Either can be on without
     * the other.
     */
    public function milestoneReached(User $user, BreakMilestoneFiring $firing, ?CarbonInterface $asOf = null): ?ToolboxOutboxEvent
    {
        if (! $this->enabled()) {
            return null;
        }

        $asOf = $asOf === null ? CarbonImmutable::now() : CarbonImmutable::parse($asOf);
        $workDate = $firing->work_date->toDateString();

        return $this->push($user, self::MILESTONE, [
            'work_date' => $workDate,
            'kind' => $firing->kind->value,
            'threshold_minutes' => $firing->threshold_minutes,
            // The true crossing instant, which may predate this message - see
            // BreakMilestoneEvaluator on why noticed_at can lag.
            'crossed_at' => $firing->crossed_at->toIso8601String(),
            'noticed_at' => $firing->noticed_at->toIso8601String(),
            'totals' => $this->totals->forDay($user, $workDate, $asOf),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function push(User $user, string $event, array $data): ToolboxOutboxEvent
    {
        // Shaped like notification.send minus `channels`: a broadcast is web by
        // definition. `event` is what the client listens for, with a leading
        // dot - Echo's custom-name convention: .listen('.break.updated', ...).
        $payload = [
            'event' => $event,
            'users' => [[
                'id' => $user->id,
                'data' => $data,
            ]],
        ];

        $subject = 'notifications.v1.broadcast.send';

        $row = $this->outbox->record($subject, $this->events->make($subject, $payload));

        // afterCommit for the same reason as BreakNotifier: this runs inside
        // BreakWriteService's transaction and every queue connection has
        // after_commit => false, so without it a worker can claim the job
        // before the row exists.
        PublishOutboxEventJob::dispatch($row->id)->afterCommit();

        return $row;
    }
}
