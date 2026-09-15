<?php

namespace App\Services\Breaks;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The only place work-day arithmetic lives.
 *
 * A work day runs from cutoff_hour to cutoff_hour, and a break belongs to the
 * day it STARTED in — a break that starts 23:00 and ends 07:00 the next morning
 * counts ENTIRELY against the day it started, never split across two.
 *
 * Constructed with its cutoff rather than reading config() internally, so it is
 * testable as pure arithmetic with no booted application, and so "which cutoff
 * is this instance using" is always answerable. Bound in AppServiceProvider.
 *
 * The cutoff is UTC by default and should stay that way: a UTC boundary never
 * shifts under DST. The timezone is configurable for completeness, but a
 * non-UTC value makes the boundary move twice a year.
 */
final class WorkDayResolver
{
    public function __construct(
        private readonly int $cutoffHour,
        private readonly string $timezone,
    ) {}

    /**
     * The work date a given instant belongs to, as 'Y-m-d'.
     *
     * Subtraction rather than a comparison chain, because subtraction gets the
     * half-open boundary right for free: with a 06:00 cutoff, 05:59:59 minus
     * six hours lands on the previous date and 06:00:00 lands on this one.
     */
    public function workDateFor(CarbonInterface $instant): string
    {
        return CarbonImmutable::parse($instant)
            ->setTimezone($this->timezone)
            ->subHours($this->cutoffHour)
            ->format('Y-m-d');
    }

    /**
     * The work date that is current right now.
     */
    public function currentWorkDate(?CarbonInterface $now = null): string
    {
        return $this->workDateFor($now ?? CarbonImmutable::now());
    }

    /**
     * Half-open window [start, end) for a work date.
     *
     * For display and for validating a manual entry only. Aggregation must
     * filter on the stored break_entries.work_date column and NEVER on a
     * started_at range, because a break may legitimately END outside its own
     * day's window.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function windowFor(string $workDate): array
    {
        $start = CarbonImmutable::parse($workDate, $this->timezone)
            ->startOfDay()
            ->addHours($this->cutoffHour);

        return [$start, $start->addDay()];
    }

    /**
     * The oldest work date still inside the retention window. Everything
     * strictly before it is prunable.
     */
    public function oldestRetainedWorkDate(int $days, ?CarbonInterface $now = null): string
    {
        return CarbonImmutable::parse($this->currentWorkDate($now), $this->timezone)
            ->subDays($days)
            ->format('Y-m-d');
    }

    public function cutoffHour(): int
    {
        return $this->cutoffHour;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }
}
