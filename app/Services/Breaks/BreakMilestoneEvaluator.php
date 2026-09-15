<?php

namespace App\Services\Breaks;

use App\Enums\MilestoneKind;
use App\Models\BreakEntry;
use App\Models\BreakMilestoneFiring;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Decides which of a user's thresholds have been crossed on a work day.
 *
 * THE HONEST LIMITATION: a stateless API has no in-process timer. A user on a
 * break at minute 19 crosses their 20-minute milestone with no request in
 * flight and nothing here awake to notice. So evaluation runs on the write
 * paths and on every read of the CURRENT work day, and a firing records BOTH
 * crossed_at - the true instant, computed from the entries - and noticed_at,
 * when evaluation actually ran. Scheduling breaks:evaluate-milestones shortens
 * the gap; it changes no recorded value.
 *
 * All arithmetic is in seconds. Minutes appear only at the edges.
 */
class BreakMilestoneEvaluator
{
    public function __construct(
        private readonly BreakSettingsService $settings,
        private readonly BreakNotifier $notifier,
        private readonly WorkDayResolver $workDays,
        private readonly BreakBroadcaster $broadcaster,
    ) {}

    /**
     * Fire anything crossed and not yet fired. Never removes a firing.
     *
     * @return Collection<int, BreakMilestoneFiring> the firings created by THIS call
     */
    public function evaluate(User $user, string $workDate, ?CarbonInterface $asOf = null): Collection
    {
        $asOf = $asOf === null ? CarbonImmutable::now() : CarbonImmutable::parse($asOf);

        $entries = $this->countedEntries($user, $workDate);
        $targets = $this->targets($user);

        if ($targets === []) {
            return collect();
        }

        $alreadyFired = $this->firings($user, $workDate)
            ->mapWithKeys(fn (BreakMilestoneFiring $f) => [$this->key($f->kind->value, $f->threshold_minutes) => true]);

        $created = collect();
        $running = 0;

        foreach ($entries as $entry) {
            $length = $entry->elapsedSeconds($asOf);

            foreach ($targets as $target) {
                if ($alreadyFired->has($target['key'])) {
                    continue;
                }

                // Crossed strictly inside this entry: the accumulated total was
                // below the threshold before it and reaches it during.
                if ($running < $target['seconds'] && $target['seconds'] <= $running + $length) {
                    $firing = $this->recordFiring(
                        $user,
                        $workDate,
                        $target,
                        // Exact, including mid-break: the instant the running
                        // total actually hit the threshold.
                        $entry->started_at->addSeconds($target['seconds'] - $running),
                        $asOf,
                    );

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
        $asOf = $asOf === null ? CarbonImmutable::now() : CarbonImmutable::parse($asOf);

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
     * A day is not "past" while a break belonging to it is still accruing. A
     * break started at 23:00 carries yesterday's work date, so once the cutoff
     * passes it is invisible to an evaluation of today - and yesterday would
     * otherwise never be evaluated again on any read path. A user on a break
     * across 06:00 would silently stop getting milestones.
     *
     * @return array<int, string>
     */
    public function openWorkDates(User $user, ?CarbonInterface $asOf = null): array
    {
        $asOf = $asOf === null ? CarbonImmutable::now() : CarbonImmutable::parse($asOf);

        $dates = BreakEntry::query()
            ->where('user_id', $user->id)
            ->running()
            ->pluck('work_date')
            ->map(fn ($date) => CarbonImmutable::parse($date)->format('Y-m-d'))
            ->all();

        $dates[] = $this->workDays->currentWorkDate($asOf);

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
     * Counted break time on a work day, in seconds. Excluded types never
     * contribute; a running break contributes its elapsed-so-far.
     */
    public function countedSeconds(User $user, string $workDate, ?CarbonInterface $asOf = null): int
    {
        $asOf = $asOf === null ? CarbonImmutable::now() : CarbonImmutable::parse($asOf);

        return $this->countedEntries($user, $workDate)
            ->sum(fn (BreakEntry $entry) => $entry->elapsedSeconds($asOf));
    }

    /**
     * @return Collection<int, BreakMilestoneFiring>
     */
    public function firings(User $user, string $workDate): Collection
    {
        return BreakMilestoneFiring::query()
            ->where('user_id', $user->id)
            ->where('work_date', $workDate)
            ->orderBy('threshold_minutes')
            ->orderBy('kind')
            ->get();
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
     * Every threshold worth watching, ascending: the user's own milestones,
     * plus the allowance itself as a separate kind.
     *
     * @return array<int, array{kind: string, minutes: int, seconds: int, key: string}>
     */
    private function targets(User $user): array
    {
        $targets = [];

        foreach ($this->settings->thresholds($user) as $minutes) {
            $targets[] = [
                'kind' => MilestoneKind::Milestone->value,
                'minutes' => $minutes,
                'seconds' => $minutes * 60,
                'key' => $this->key(MilestoneKind::Milestone->value, $minutes),
            ];
        }

        $allowance = $this->settings->forUser($user)->daily_allowance_minutes;

        if ($allowance > 0) {
            $targets[] = [
                'kind' => MilestoneKind::Allowance->value,
                'minutes' => $allowance,
                'seconds' => $allowance * 60,
                'key' => $this->key(MilestoneKind::Allowance->value, $allowance),
            ];
        }

        usort($targets, fn (array $a, array $b) => $a['seconds'] <=> $b['seconds']);

        return $targets;
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

        $this->notifier->announce($user, $firing, $this->totalsFor($user, $workDate, $noticedAt));

        // Both, and independently gated: the broadcast lands on the socket now
        // so the UI can react, the notification is the durable record in the
        // bell. Either may be switched off without the other.
        $this->broadcaster->milestoneReached($user, $firing, $noticedAt);

        return $firing->refresh();
    }

    /**
     * @return array<string, int>
     */
    private function totalsFor(User $user, string $workDate, CarbonImmutable $asOf): array
    {
        $counted = $this->countedSeconds($user, $workDate, $asOf);
        $allowance = $this->settings->forUser($user)->daily_allowance_minutes;

        $countedMinutes = intdiv($counted, 60);

        return [
            'counted_minutes' => $countedMinutes,
            'allowance_minutes' => $allowance,
            'remaining_minutes' => max(0, $allowance - $countedMinutes),
            'over_minutes' => max(0, $countedMinutes - $allowance),
        ];
    }

    private function key(string $kind, int $minutes): string
    {
        return $kind.'|'.$minutes;
    }
}
