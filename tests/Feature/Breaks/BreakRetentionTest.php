<?php

namespace Tests\Feature\Breaks;

use App\Models\BreakEntry;
use App\Models\BreakMilestoneFiring;
use App\Models\Note;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\BreakTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BreakRetentionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BreakTypeSeeder::class);

        CarbonImmutable::setTestNow('2026-09-15T12:00:00Z');

        $this->user = User::query()->create(['id' => 9, 'name' => 'Dana', 'email' => 'dana@example.com']);
    }

    private function entryOn(string $date): BreakEntry
    {
        return BreakEntry::factory()->for($this->user)->ofType('coffee_break')
            ->between($date.'T14:00:00Z', $date.'T14:10:00Z')
            ->create();
    }

    public function test_breaks_older_than_the_window_are_deleted(): void
    {
        // Horizon with 30 days from work day 2026-09-15 is 2026-08-16.
        $old = $this->entryOn('2026-08-10');
        $kept = $this->entryOn('2026-09-10');

        $this->artisan('breaks:prune')->assertSuccessful();

        $this->assertDatabaseMissing('break_entries', ['id' => $old->id]);
        $this->assertDatabaseHas('break_entries', ['id' => $kept->id]);
    }

    public function test_the_day_on_the_horizon_itself_is_kept(): void
    {
        $onHorizon = $this->entryOn('2026-08-16');

        $this->artisan('breaks:prune')->assertSuccessful();

        $this->assertDatabaseHas('break_entries', ['id' => $onHorizon->id]);
    }

    public function test_todays_breaks_are_never_pruned(): void
    {
        $today = $this->entryOn('2026-09-15');

        $this->artisan('breaks:prune')->assertSuccessful();

        $this->assertDatabaseHas('break_entries', ['id' => $today->id]);
    }

    /**
     * The note table is polymorphic, so there is no foreign key and nothing
     * cascades. This is the orphan-row bug the test exists to prevent.
     */
    public function test_pruning_removes_the_polymorphic_notes_too(): void
    {
        $old = $this->entryOn('2026-08-10');
        $old->notes()->create(['body' => 'should not survive', 'created_by' => $this->user->id]);

        $this->artisan('breaks:prune')->assertSuccessful();

        $this->assertSame(0, Note::withTrashed()->count());
    }

    public function test_pruning_removes_the_milestone_firings_for_those_days(): void
    {
        $this->entryOn('2026-08-10');

        BreakMilestoneFiring::query()->create([
            'user_id' => $this->user->id,
            'work_date' => '2026-08-10',
            'kind' => 'milestone',
            'threshold_minutes' => 20,
            'crossed_at' => '2026-08-10 14:20:00',
            'noticed_at' => '2026-08-10 14:20:00',
            'counted_seconds_at_cross' => 1200,
        ]);

        $this->artisan('breaks:prune')->assertSuccessful();

        $this->assertDatabaseCount('break_milestone_firings', 0);
    }

    public function test_a_dry_run_reports_counts_and_changes_nothing(): void
    {
        $old = $this->entryOn('2026-08-10');
        $old->notes()->create(['body' => 'still here', 'created_by' => $this->user->id]);

        $this->artisan('breaks:prune --dry-run')
            ->expectsOutputToContain('Pruning work dates before 2026-08-16')
            ->expectsOutputToContain('Dry run - nothing was deleted.')
            ->assertSuccessful();

        $this->assertDatabaseHas('break_entries', ['id' => $old->id]);
        $this->assertSame(1, Note::withTrashed()->count());
    }

    public function test_the_days_option_overrides_the_configured_window(): void
    {
        $recent = $this->entryOn('2026-09-10');

        // A 1-day window makes even a five-day-old entry prunable.
        $this->artisan('breaks:prune --days=1')->assertSuccessful();

        $this->assertDatabaseMissing('break_entries', ['id' => $recent->id]);
    }

    public function test_a_negative_window_is_refused(): void
    {
        $this->artisan('breaks:prune --days=-1')->assertFailed();
    }

    public function test_another_users_breaks_are_pruned_on_the_same_horizon(): void
    {
        $other = User::query()->create(['id' => 77, 'name' => 'Sam', 'email' => 'sam@example.com']);

        BreakEntry::factory()->for($other)->ofType('smoking')
            ->between('2026-08-10T14:00:00Z', '2026-08-10T14:10:00Z')
            ->create();

        $this->artisan('breaks:prune')->assertSuccessful();

        $this->assertDatabaseCount('break_entries', 0);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }
}
