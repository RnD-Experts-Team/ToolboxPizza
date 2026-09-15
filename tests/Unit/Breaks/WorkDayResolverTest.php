<?php

namespace Tests\Unit\Breaks;

use App\Services\Breaks\WorkDayResolver;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Pure arithmetic — no booted application, no database. The resolver takes its
 * cutoff by construction precisely so this can be true.
 */
class WorkDayResolverTest extends TestCase
{
    private function resolver(int $cutoffHour = 6, string $timezone = 'UTC'): WorkDayResolver
    {
        return new WorkDayResolver($cutoffHour, $timezone);
    }

    public function test_an_instant_one_second_before_the_cutoff_belongs_to_the_previous_work_day(): void
    {
        $this->assertSame(
            '2026-09-14',
            $this->resolver()->workDateFor(CarbonImmutable::parse('2026-09-15T05:59:59Z'))
        );
    }

    public function test_an_instant_exactly_at_the_cutoff_starts_the_new_work_day(): void
    {
        $this->assertSame(
            '2026-09-15',
            $this->resolver()->workDateFor(CarbonImmutable::parse('2026-09-15T06:00:00Z'))
        );
    }

    /**
     * The case people assume is "today". With a 06:00 cutoff, midnight is still
     * the tail of yesterday's work day.
     */
    public function test_midnight_utc_belongs_to_the_previous_work_date(): void
    {
        $this->assertSame(
            '2026-09-14',
            $this->resolver()->workDateFor(CarbonImmutable::parse('2026-09-15T00:00:00Z'))
        );
    }

    public function test_a_break_that_ends_after_the_next_cutoff_still_belongs_to_the_day_it_started(): void
    {
        $resolver = $this->resolver();

        $startedAt = CarbonImmutable::parse('2026-09-15T23:00:00Z');
        $endedAt = CarbonImmutable::parse('2026-09-16T07:00:00Z');

        // The end is already inside the NEXT work day, but the entry is filed
        // whole against the day it started in.
        $this->assertSame('2026-09-15', $resolver->workDateFor($startedAt));
        $this->assertSame('2026-09-16', $resolver->workDateFor($endedAt));
    }

    public function test_the_window_for_a_work_date_is_half_open(): void
    {
        [$start, $end] = $this->resolver()->windowFor('2026-09-15');

        $this->assertSame('2026-09-15T06:00:00+00:00', $start->toIso8601String());
        $this->assertSame('2026-09-16T06:00:00+00:00', $end->toIso8601String());

        // The exclusive end already belongs to the next work day.
        $this->assertSame('2026-09-16', $this->resolver()->workDateFor($end));
    }

    public function test_the_cutoff_hour_comes_from_construction_not_a_constant(): void
    {
        $atFive = $this->resolver(5)->workDateFor(CarbonImmutable::parse('2026-09-15T05:30:00Z'));
        $atSix = $this->resolver(6)->workDateFor(CarbonImmutable::parse('2026-09-15T05:30:00Z'));

        $this->assertSame('2026-09-15', $atFive);
        $this->assertSame('2026-09-14', $atSix);
    }

    public function test_a_midnight_cutoff_degrades_to_the_plain_calendar_date(): void
    {
        $this->assertSame(
            '2026-09-15',
            $this->resolver(0)->workDateFor(CarbonImmutable::parse('2026-09-15T00:00:00Z'))
        );
    }

    /**
     * The boundary is UTC, so it does not move on the days a local-time cutoff
     * would. Both of these are US DST transition dates.
     */
    public function test_the_boundary_does_not_drift_across_dst_transitions(): void
    {
        $resolver = $this->resolver();

        // Spring forward (2026-03-08) and fall back (2026-11-01).
        $this->assertSame('2026-03-07', $resolver->workDateFor(CarbonImmutable::parse('2026-03-08T05:59:59Z')));
        $this->assertSame('2026-03-08', $resolver->workDateFor(CarbonImmutable::parse('2026-03-08T06:00:00Z')));
        $this->assertSame('2026-10-31', $resolver->workDateFor(CarbonImmutable::parse('2026-11-01T05:59:59Z')));
        $this->assertSame('2026-11-01', $resolver->workDateFor(CarbonImmutable::parse('2026-11-01T06:00:00Z')));
    }

    public function test_the_current_work_date_is_derived_from_now(): void
    {
        $this->assertSame(
            '2026-09-14',
            $this->resolver()->currentWorkDate(CarbonImmutable::parse('2026-09-15T02:00:00Z'))
        );
    }

    public function test_the_retention_horizon_counts_back_from_the_current_work_date(): void
    {
        $this->assertSame(
            '2026-08-16',
            $this->resolver()->oldestRetainedWorkDate(30, CarbonImmutable::parse('2026-09-15T12:00:00Z'))
        );
    }
}
