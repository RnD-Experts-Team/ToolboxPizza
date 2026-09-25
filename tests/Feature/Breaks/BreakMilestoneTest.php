<?php

namespace Tests\Feature\Breaks;

use App\Models\BreakEntry;
use App\Models\BreakMilestoneFiring;
use App\Models\BreakType;
use App\Services\Breaks\BreakMilestoneService;
use Carbon\CarbonImmutable;
use Database\Seeders\BreakTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

class BreakMilestoneTest extends TestCase
{
    use FakesAuthServer, RefreshDatabase;

    private BreakMilestoneService $evaluator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAuthServer();
        $this->seed(BreakTypeSeeder::class);

        CarbonImmutable::setTestNow('2026-09-15T21:00:00Z');

        $this->postJson('/api/v1/break-settings', ['daily_allowance_minutes' => 50], $this->headers())->assertOk();
        $this->postJson('/api/v1/break-milestones', ['thresholds' => [20, 40]], $this->headers())->assertOk();

        $this->evaluator = app(BreakMilestoneService::class);
    }

    private function entry(string $slug, string $from, ?string $to): BreakEntry
    {
        $factory = BreakEntry::factory()->for($this->authUser)->ofType($slug)->between($from, $to);

        return $to === null ? $factory->running()->create() : $factory->create();
    }

    private function evaluate(string $workDate = '2026-09-15'): void
    {
        $this->evaluator->evaluate($this->authUser, $workDate);
    }

    public function test_a_threshold_fires_once_it_is_crossed(): void
    {
        $this->entry('coffee_break', '2026-09-15T14:00:00Z', '2026-09-15T14:25:00Z');

        $this->evaluate();

        $this->assertDatabaseHas('break_milestone_firings', [
            'user_id' => $this->authUser->id,
            'work_date' => '2026-09-15',
            'kind' => 'milestone',
            'threshold_minutes' => 20,
        ]);
    }

    public function test_a_threshold_not_yet_reached_does_not_fire(): void
    {
        $this->entry('coffee_break', '2026-09-15T14:00:00Z', '2026-09-15T14:19:00Z');

        $this->evaluate();

        $this->assertDatabaseCount('break_milestone_firings', 0);
    }

    public function test_the_same_threshold_does_not_fire_twice_on_the_same_work_day(): void
    {
        $this->entry('coffee_break', '2026-09-15T14:00:00Z', '2026-09-15T14:25:00Z');

        $this->evaluate();
        $this->evaluate();
        $this->evaluate();

        $this->assertSame(1, BreakMilestoneFiring::query()->count());
    }

    /**
     * The unique index is the arbiter, not PHP: two concurrent polls of the
     * same running break compute the same crossing.
     */
    public function test_two_evaluations_in_the_same_tick_produce_one_firing(): void
    {
        $this->entry('coffee_break', '2026-09-15T14:00:00Z', '2026-09-15T14:25:00Z');

        $asOf = CarbonImmutable::now();
        $first = $this->evaluator->evaluate($this->authUser, '2026-09-15', $asOf);
        $second = $this->evaluator->evaluate($this->authUser, '2026-09-15', $asOf);

        $this->assertCount(1, $first);
        $this->assertCount(0, $second);
        $this->assertSame(1, BreakMilestoneFiring::query()->count());
    }

    public function test_the_same_threshold_fires_again_on_the_next_work_day(): void
    {
        $this->entry('coffee_break', '2026-09-15T14:00:00Z', '2026-09-15T14:25:00Z');
        $this->evaluate('2026-09-15');

        CarbonImmutable::setTestNow('2026-09-16T21:00:00Z');
        $this->entry('coffee_break', '2026-09-16T14:00:00Z', '2026-09-16T14:25:00Z');
        $this->evaluate('2026-09-16');

        $this->assertSame(2, BreakMilestoneFiring::query()->where('threshold_minutes', 20)->count());
    }

    public function test_one_long_break_fires_every_threshold_it_crosses(): void
    {
        // 55 minutes crosses 20, 40 and the 50-minute allowance.
        $this->entry('smoking', '2026-09-15T14:00:00Z', '2026-09-15T14:55:00Z');

        $this->evaluate();

        $this->assertSame([20, 40, 50], BreakMilestoneFiring::query()
            ->orderBy('threshold_minutes')->pluck('threshold_minutes')->all());
    }

    /**
     * crossed_at is computed from the entries, so it is exact even when the
     * crossing happened mid-break and nobody looked until much later.
     */
    public function test_crossed_at_is_the_computed_instant_not_the_moment_of_observation(): void
    {
        $this->entry('coffee_break', '2026-09-15T14:00:00Z', '2026-09-15T14:25:00Z');

        CarbonImmutable::setTestNow('2026-09-15T19:30:00Z');
        $this->evaluate();

        $firing = BreakMilestoneFiring::query()->where('threshold_minutes', 20)->firstOrFail();

        // 20 minutes into a break that started at 14:00.
        $this->assertSame('2026-09-15 14:20:00', $firing->crossed_at->toDateTimeString());
        $this->assertSame('2026-09-15 19:30:00', $firing->noticed_at->toDateTimeString());
        $this->assertTrue($firing->crossed_at->lessThan($firing->noticed_at));
    }

    public function test_excluded_break_time_never_advances_a_milestone(): void
    {
        $this->entry('maintenance', '2026-09-15T14:00:00Z', '2026-09-15T15:00:00Z');   // 60m excluded
        $this->entry('coffee_break', '2026-09-15T16:00:00Z', '2026-09-15T16:05:00Z');  // 5m counted

        $this->evaluate();

        $this->assertDatabaseCount('break_milestone_firings', 0);
    }

    /**
     * A milestone set AT the allowance and the allowance itself are two
     * different things to be told, so they fire independently.
     */
    public function test_the_allowance_firing_is_separate_from_a_milestone_at_the_same_number(): void
    {
        $this->postJson('/api/v1/break-milestones', ['thresholds' => [50]], $this->headers())->assertOk();

        $this->entry('smoking', '2026-09-15T14:00:00Z', '2026-09-15T14:55:00Z');

        $this->evaluate();

        // pluck() honours the model's casts, so these come back as MilestoneKind.
        $kinds = BreakMilestoneFiring::query()
            ->where('threshold_minutes', 50)
            ->pluck('kind')
            ->map(fn ($kind) => $kind->value)
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['allowance', 'milestone'], $kinds);
    }

    public function test_deleting_a_break_reaps_a_firing_the_day_no_longer_justifies(): void
    {
        $entry = $this->entry('coffee_break', '2026-09-15T14:00:00Z', '2026-09-15T14:25:00Z');
        $this->evaluate();
        $this->assertSame(1, BreakMilestoneFiring::query()->count());

        $this->deleteJson("/api/v1/breaks/{$entry->id}", [], $this->headers())->assertNoContent();

        $this->assertSame(0, BreakMilestoneFiring::query()->count());
    }

    /**
     * A firing that was already ANNOUNCED stays, because a notification cannot
     * be unsent. This is what keeps "at most once" honest across an edit that
     * lowers the total and then raises it again.
     */
    public function test_a_firing_that_was_already_announced_is_never_reaped(): void
    {
        config(['toolbox.notifications.enabled' => true]);

        $entry = $this->entry('coffee_break', '2026-09-15T14:00:00Z', '2026-09-15T14:25:00Z');
        $this->evaluate();

        $firing = BreakMilestoneFiring::query()->firstOrFail();
        $this->assertNotNull($firing->outbox_event_id);

        $this->deleteJson("/api/v1/breaks/{$entry->id}", [], $this->headers())->assertNoContent();

        $this->assertSame(1, BreakMilestoneFiring::query()->count());
    }

    public function test_shortening_a_break_reaps_an_unannounced_firing(): void
    {
        $entry = $this->entry('coffee_break', '2026-09-15T14:00:00Z', '2026-09-15T14:25:00Z');
        $this->evaluate();
        $this->assertSame(1, BreakMilestoneFiring::query()->count());

        $this->postJson("/api/v1/breaks/{$entry->id}", ['ended_at' => '2026-09-15T14:05:00Z'], $this->headers())
            ->assertOk();

        $this->assertSame(0, BreakMilestoneFiring::query()->count());
    }

    public function test_editing_a_break_across_the_cutoff_reconciles_both_work_days(): void
    {
        CarbonImmutable::setTestNow('2026-09-16T12:00:00Z');

        // Lands on work day 2026-09-16 and crosses the 20-minute threshold.
        $entry = $this->entry('coffee_break', '2026-09-16T07:00:00Z', '2026-09-16T07:25:00Z');
        $this->evaluate('2026-09-16');

        $this->assertSame(1, BreakMilestoneFiring::query()->where('work_date', '2026-09-16')->count());

        // Move it before the cutoff: it now belongs to 2026-09-15.
        $this->postJson("/api/v1/breaks/{$entry->id}", ['started_at' => '2026-09-16T05:00:00Z'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.work_date', '2026-09-15');

        // The day it left no longer justifies its firing...
        $this->assertSame(0, BreakMilestoneFiring::query()->where('work_date', '2026-09-16')->count());
        // ...and the day it joined now crosses thresholds of its own.
        $this->assertGreaterThan(0, BreakMilestoneFiring::query()->where('work_date', '2026-09-15')->count());
    }

    /**
     * Firings key on the threshold integer, not on a break_milestones row id,
     * so removing and re-adding "20" mid-day cannot re-arm it.
     */
    public function test_a_deleted_and_recreated_threshold_does_not_refire_the_same_day(): void
    {
        $this->entry('coffee_break', '2026-09-15T14:00:00Z', '2026-09-15T14:25:00Z');
        $this->evaluate();
        $this->assertSame(1, BreakMilestoneFiring::query()->where('threshold_minutes', 20)->count());

        $this->postJson('/api/v1/break-milestones', ['thresholds' => [40]], $this->headers())->assertOk();
        $this->postJson('/api/v1/break-milestones', ['thresholds' => [20, 40]], $this->headers())->assertOk();

        $this->evaluate();

        $this->assertSame(1, BreakMilestoneFiring::query()->where('threshold_minutes', 20)->count());
    }

    public function test_a_running_break_crosses_a_threshold_when_the_active_endpoint_is_read(): void
    {
        CarbonImmutable::setTestNow('2026-09-15T14:00:00Z');
        $this->postJson('/api/v1/breaks/start', [
            'break_type_id' => BreakType::query()->where('slug', 'coffee_break')->value('id'),
        ], $this->headers())->assertCreated();

        $this->assertDatabaseCount('break_milestone_firings', 0);

        // The client polls to tick its timer; that read is what notices.
        CarbonImmutable::setTestNow('2026-09-15T14:21:00Z');
        $this->getJson('/api/v1/breaks/active', $this->headers())->assertOk();

        $this->assertDatabaseHas('break_milestone_firings', ['threshold_minutes' => 20, 'kind' => 'milestone']);
    }

    /**
     * REGRESSION. A break started before the cutoff keeps YESTERDAY's work date,
     * so once 06:00 passes it is invisible to an evaluation of today - and
     * yesterday is never evaluated again on a read path. Evaluating only the
     * current work day silently stopped noticing milestones for anyone whose
     * break spanned the cutoff.
     */
    public function test_a_break_spanning_the_cutoff_keeps_firing_milestones(): void
    {
        // Starts at 05:50, so it belongs to work day 2026-09-15.
        CarbonImmutable::setTestNow('2026-09-16T05:50:00Z');
        $this->postJson('/api/v1/breaks/start', [
            'break_type_id' => BreakType::query()->where('slug', 'coffee_break')->value('id'),
        ], $this->headers())->assertCreated();

        // 06:10 - the cutoff has passed, so "today" is now 2026-09-16 while the
        // running break still belongs to 2026-09-15. It has run 20 minutes.
        CarbonImmutable::setTestNow('2026-09-16T06:10:00Z');
        $this->getJson('/api/v1/breaks/active', $this->headers())->assertOk();

        $this->assertDatabaseHas('break_milestone_firings', [
            'work_date' => '2026-09-15',
            'kind' => 'milestone',
            'threshold_minutes' => 20,
        ]);
    }

    public function test_the_day_view_of_a_still_running_previous_day_still_evaluates(): void
    {
        CarbonImmutable::setTestNow('2026-09-16T05:50:00Z');
        $this->postJson('/api/v1/breaks/start', [
            'break_type_id' => BreakType::query()->where('slug', 'coffee_break')->value('id'),
        ], $this->headers())->assertCreated();

        CarbonImmutable::setTestNow('2026-09-16T06:10:00Z');
        $this->getJson('/api/v1/breaks/day?date=2026-09-15', $this->headers())->assertOk();

        $this->assertDatabaseHas('break_milestone_firings', ['work_date' => '2026-09-15']);
    }

    /**
     * The other half of that rule: a settled day is strictly read-only, so
     * reading it must never create firings.
     */
    public function test_a_settled_day_with_nothing_running_is_never_evaluated_by_a_read(): void
    {
        $this->entry('coffee_break', '2026-09-15T14:00:00Z', '2026-09-15T14:25:00Z');

        CarbonImmutable::setTestNow('2026-09-20T12:00:00Z');

        $this->getJson('/api/v1/breaks/day?date=2026-09-15', $this->headers())->assertOk();
        $this->getJson('/api/v1/breaks/active', $this->headers())->assertOk();

        $this->assertDatabaseCount('break_milestone_firings', 0);
    }

    public function test_the_day_summary_lists_fired_and_pending_thresholds(): void
    {
        $this->entry('coffee_break', '2026-09-15T14:00:00Z', '2026-09-15T14:25:00Z');
        $this->evaluate();

        $milestones = $this->getJson('/api/v1/breaks/day?date=2026-09-15', $this->headers())
            ->assertOk()
            ->json('data.milestones');

        $this->assertSame([20, 40], $milestones['thresholds']);
        $this->assertSame(20, $milestones['fired'][0]['threshold_minutes']);
        $this->assertFalse($milestones['fired'][0]['notified']);
        $this->assertSame([40], $milestones['pending']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }
}
