<?php

namespace App\Services\Breaks;

use App\Enums\MilestoneKind;
use App\Models\BreakEntry;
use App\Models\BreakMilestone;
use App\Models\BreakMilestoneFiring;
use App\Models\User;
use App\Models\UserBreakSetting;
use App\Services\ToolboxEvents\ToolboxOutboxService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Decides which of a user's thresholds have been crossed on a work day, and
 * tells them.
 *
 * THE HONEST LIMITATION: a stateless API has no in-process timer. A user on a
 * break at minute 19 crosses their 20-minute milestone with no request in
 * flight and nothing here awake to notice. So evaluation runs on the write
 * paths and on every read of an OPEN work day, and a firing records BOTH
 * crossed_at - the true instant, computed from the entries - and noticed_at,
 * when evaluation actually ran. Scheduling breaks:evaluate-milestones shortens
 * the gap; it changes no recorded value.
 */
class BreakMilestoneService
{
    public function __construct(
        private readonly WorkDayResolver $workDays,
        private readonly BreakQueryService $reader,
        private readonly ToolboxOutboxService $outbox,
    ) {}

    /**
     * Fire anything crossed and not yet fired. Never removes a firing.
     *
     * @return Collection<int, BreakMilestoneFiring> the firings created by THIS call
     */
    public function evaluate(User $user, string $workDate, ?CarbonInterface $asOf = null): Collection
    {
        $asOf = $this->asOf($asOf);
        $targets = $this->targets($user);

        if ($targets === []) {
            return collect();
        }

        $alreadyFired = BreakMilestoneFiring::forDay($user, $workDate)
            ->mapWithKeys(fn (BreakMilestoneFiring $f) => [$this->key($f->kind->value, $f->threshold_minutes) => true]);

        $created = collect();
        $running = 0;

        foreach ($this->countedEntries($user, $workDate) as $entry) {
            $length = $entry->elapsedSeconds($asOf);

            foreach ($targets as $target) {
                if ($alreadyFired->has($target['key'])) {
                    continue;
                }

                // Crossed strictly inside this entry: the accumulated total was
                // below the threshold before it and reaches it during.
                if ($running < $target['seconds'] && $target['seconds'] <= $running + $length) {
                    // Exact, including mid-break: the instant the running total
                    // actually hit the threshold.
                    $crossedAt = $entry->started_at->addSeconds($target['seconds'] - $running);

                    $firing = $this->recordFiring($user, $workDate, $target, $crossedAt, $asOf);

                    $alreadyFired->put($target['key'], true);

                    if ($firing !== null) {
                        $created->push($firing);
                    }
                }
            }

            $running += $length;
        }

        return $created;
    }

    /**
     * Evaluate, and also reap firings the day's totals no longer justify.
     *
     * Used after an edit or a delete. A firing that was already ANNOUNCED is
     * never reaped - the user has been told, and a notification cannot be
     * unsent - which is what keeps "at most once" honest across an edit that
     * lowers the total and then raises it again.
     *
     * @return Collection<int, BreakMilestoneFiring>
     */
    public function reconcile(User $user, string $workDate, ?CarbonInterface $asOf = null): Collection
    {
        $asOf = $this->asOf($asOf);

        $counted = $this->countedSeconds($user, $workDate, $asOf);

        BreakMilestoneFiring::query()
            ->where('user_id', $user->id)
            ->where('work_date', $workDate)
            ->whereNull('outbox_event_id')
            ->get()
            ->filter(fn (BreakMilestoneFiring $f) => $f->threshold_minutes * 60 > $counted)
            ->each(fn (BreakMilestoneFiring $f) => $f->delete());

        return $this->evaluate($user, $workDate, $asOf);
    }

    /**
     * The work dates a read may still evaluate: the current one, plus the day
     * of any break that is still running.
     *
     * A break started at 23:00 carries yesterday's work date, so once the
     * cutoff passes it is invisible to an evaluation of today - and yesterday
     * would otherwise never be evaluated again on any read path.
     *
     * @return array<int, string>
     */
    public function openWorkDates(User $user, ?CarbonInterface $asOf = null): array
    {
        $dates = BreakEntry::query()
            ->where('user_id', $user->id)
            ->running()
            ->pluck('work_date')
            ->map(fn ($date) => CarbonImmutable::parse($date)->format('Y-m-d'))
            ->all();

        $dates[] = $this->workDays->currentWorkDate($this->asOf($asOf));

        return array_values(array_unique($dates));
    }

    /**
     * Evaluate every work day that is still open for this user.
     *
     * @return Collection<int, BreakMilestoneFiring>
     */
    public function evaluateOpen(User $user, ?CarbonInterface $asOf = null): Collection
    {
        $created = collect();

        foreach ($this->openWorkDates($user, $asOf) as $workDate) {
            $created = $created->merge($this->evaluate($user, $workDate, $asOf));
        }

        return $created;
    }

    /**
     * Evaluate one work day, but ONLY if it is still open.
     *
     * Exporting last Tuesday must never create firing rows, which is why a
     * settled date is strictly read-only.
     */
    public function evaluateIfOpen(User $user, string $workDate): void
    {
        if (in_array($workDate, $this->openWorkDates($user), true)) {
            $this->evaluate($user, $workDate);
        }
    }

    /**
     * Counted break time on a work day, in seconds. Excluded types never
     * contribute; a running break contributes its elapsed-so-far.
     */
    public function countedSeconds(User $user, string $workDate, ?CarbonInterface $asOf = null): int
    {
        $asOf = $this->asOf($asOf);

        return $this->countedEntries($user, $workDate)
            ->sum(fn (BreakEntry $entry) => $entry->elapsedSeconds($asOf));
    }

    /**
     * @return Collection<int, BreakEntry>
     */
    private function countedEntries(User $user, string $workDate): Collection
    {
        return BreakEntry::query()
            ->where('user_id', $user->id)
            ->forWorkDate($workDate)
            ->where('counts_toward_limit', true)
            // A total order, so two entries sharing a start instant still
            // accumulate deterministically.
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Every threshold worth watching, ascending: the user's own milestones, plus
     * the allowance itself as a separate kind - a user can set a milestone AT
     * their allowance, and those are two different things to be told.
     *
     * @return array<int, array{kind: string, minutes: int, seconds: int, key: string}>
     */
    private function targets(User $user): array
    {
        $targets = [];

        foreach (BreakMilestone::thresholdsFor($user) as $minutes) {
            $targets[] = $this->target(MilestoneKind::Milestone, $minutes);
        }

        $allowance = UserBreakSetting::forUser($user)->daily_allowance_minutes;

        if ($allowance > 0) {
            $targets[] = $this->target(MilestoneKind::Allowance, $allowance);
        }

        usort($targets, fn (array $a, array $b) => $a['seconds'] <=> $b['seconds']);

        return $targets;
    }

    /**
     * @return array{kind: string, minutes: int, seconds: int, key: string}
     */
    private function target(MilestoneKind $kind, int $minutes): array
    {
        return [
            'kind' => $kind->value,
            'minutes' => $minutes,
            'seconds' => $minutes * 60,
            'key' => $this->key($kind->value, $minutes),
        ];
    }

    /**
     * @param  array{kind: string, minutes: int, seconds: int, key: string}  $target
     */
    private function recordFiring(
        User $user,
        string $workDate,
        array $target,
        CarbonImmutable $crossedAt,
        CarbonImmutable $noticedAt,
    ): ?BreakMilestoneFiring {
        $attributes = [
            'user_id' => $user->id,
            'work_date' => $workDate,
            'kind' => $target['kind'],
            'threshold_minutes' => $target['minutes'],
        ];

        // insertOrIgnore, not create: two concurrent evaluations of the same
        // running break compute the same crossing, and the unique index is the
        // only thing that can arbitrate between them. A row that did NOT insert
        // was somebody else's, and must not be announced twice.
        $inserted = BreakMilestoneFiring::query()->insertOrIgnore([
            ...$attributes,
            'crossed_at' => $crossedAt->toDateTimeString(),
            'noticed_at' => $noticedAt->toDateTimeString(),
            'counted_seconds_at_cross' => $target['seconds'],
            'created_at' => $noticedAt->toDateTimeString(),
            'updated_at' => $noticedAt->toDateTimeString(),
        ]);

        if ($inserted === 0) {
            return null;
        }

        $firing = BreakMilestoneFiring::query()->where($attributes)->firstOrFail();

        // Both, and independently gated: the notification is the durable record
        // in the bell, the broadcast lands on the socket so an open page can
        // react. Either may be switched off without the other.
        $this->notify($user, $firing, $noticedAt);
        $this->broadcast($user, $firing, $noticedAt);

        return $firing->refresh();
    }

    /**
     * Put the crossing in the user's bell. OFF by default
     * (TOOLBOX_NOTIFICATIONS_ENABLED); when off, the crossing is still recorded
     * in break_milestone_firings, it just goes nowhere.
     *
     * The outbox row is stamped back onto the firing: that stamp is what makes
     * it "announced", and an announced firing is never reaped.
     */
    private function notify(User $user, BreakMilestoneFiring $firing, CarbonImmutable $asOf): void
    {
        if (! config('toolbox.notifications.enabled')) {
            return;
        }

        $workDate = $firing->work_date->toDateString();
        $counted = intdiv($this->countedSeconds($user, $workDate, $asOf), 60);
        $allowance = UserBreakSetting::forUser($user)->daily_allowance_minutes;

        $notification = $firing->kind === MilestoneKind::Allowance
            ? [
                'type' => 'break_allowance_exceeded',
                'title' => 'Daily break allowance used up',
                // "nothing is blocked" is deliberate: the soft limit is a
                // product decision, and this is the only place the user reads it.
                'body' => sprintf(
                    "You've used %d of your %d daily break minutes - %d over. Breaks keep running; nothing is blocked.",
                    $counted,
                    $allowance,
                    max(0, $counted - $allowance),
                ),
            ]
            : [
                'type' => 'break_milestone_reached',
                'title' => sprintf('%d minutes of break used', $firing->threshold_minutes),
                'body' => sprintf(
                    "You've used %d of your %d daily break minutes. %d minutes left.",
                    $counted,
                    $allowance,
                    max(0, $allowance - $counted),
                ),
            ];

        $notification['action_url'] = rtrim((string) config('toolbox.notifications.action_url'), '/')."?date={$workDate}";

        $row = $this->outbox->notify([$user->id], $notification);

        $firing->forceFill(['outbox_event_id' => $row->id])->save();
    }

    /**
     * Push the crossing to the user's socket. OFF by default
     * (TOOLBOX_REALTIME_ENABLED).
     */
    private function broadcast(User $user, BreakMilestoneFiring $firing, CarbonImmutable $asOf): void
    {
        if (! config('toolbox.realtime.enabled')) {
            return;
        }

        $workDate = $firing->work_date->toDateString();

        $this->outbox->broadcast([$user->id], 'break.milestone', [
            'work_date' => $workDate,
            'kind' => $firing->kind->value,
            'threshold_minutes' => $firing->threshold_minutes,
            // The true crossing instant, which may predate this message.
            'crossed_at' => $firing->crossed_at->toIso8601String(),
            'noticed_at' => $firing->noticed_at->toIso8601String(),
            'totals' => $this->reader->totals($user, $workDate, $asOf),
        ]);
    }

    private function key(string $kind, int $minutes): string
    {
        return $kind.'|'.$minutes;
    }

    private function asOf(?CarbonInterface $asOf): CarbonImmutable
    {
        return $asOf === null ? CarbonImmutable::now() : CarbonImmutable::parse($asOf);
    }
}
