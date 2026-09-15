<?php

namespace Tests\Feature\Breaks;

use App\Models\BreakType;
use Carbon\CarbonImmutable;
use Database\Seeders\BreakTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

class BreakManualEntryTest extends TestCase
{
    use FakesAuthServer, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAuthServer();
        $this->seed(BreakTypeSeeder::class);

        CarbonImmutable::setTestNow('2026-09-15T20:00:00Z');
    }

    private function typeId(string $slug): int
    {
        return (int) BreakType::query()->where('slug', $slug)->value('id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function manual(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/breaks', array_merge([
            'break_type_id' => $this->typeId('coffee_break'),
            'started_at' => '2026-09-15T14:02:00Z',
            'ended_at' => '2026-09-15T14:13:00Z',
        ], $overrides), $this->headers());
    }

    public function test_a_forgotten_break_is_recorded_as_a_manual_entry(): void
    {
        $this->manual()
            ->assertCreated()
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.running', false)
            ->assertJsonPath('data.duration_seconds', 660)
            ->assertJsonPath('data.work_date', '2026-09-15');
    }

    public function test_an_entry_that_ends_before_it_starts_is_rejected(): void
    {
        $this->manual(['ended_at' => '2026-09-15T13:00:00Z'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ended_at');
    }

    public function test_an_entry_starting_in_the_future_is_rejected(): void
    {
        $this->manual([
            'started_at' => '2026-09-16T10:00:00Z',
            'ended_at' => '2026-09-16T10:10:00Z',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BREAK_STARTS_IN_FUTURE');
    }

    public function test_an_entry_older_than_the_retention_window_is_rejected(): void
    {
        // It would be created now and deleted by tonight's prune.
        $this->manual([
            'started_at' => '2026-07-01T10:00:00Z',
            'ended_at' => '2026-07-01T10:10:00Z',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BREAK_OUTSIDE_RETENTION_WINDOW');
    }

    public function test_an_entry_overlapping_an_existing_break_is_rejected(): void
    {
        $this->manual()->assertCreated();

        $this->manual([
            'break_type_id' => $this->typeId('smoking'),
            'started_at' => '2026-09-15T14:10:00Z',
            'ended_at' => '2026-09-15T14:20:00Z',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BREAK_OVERLAP')
            ->assertJsonPath('error.conflicts.0.label', 'Coffee break');

        $this->assertDatabaseCount('break_entries', 1);
    }

    public function test_an_entry_overlapping_the_currently_running_break_is_rejected(): void
    {
        CarbonImmutable::setTestNow('2026-09-15T14:00:00Z');
        $this->postJson('/api/v1/breaks/start', ['break_type_id' => $this->typeId('rest_room')], $this->headers())
            ->assertCreated();

        CarbonImmutable::setTestNow('2026-09-15T14:30:00Z');

        $this->manual([
            'started_at' => '2026-09-15T14:10:00Z',
            'ended_at' => '2026-09-15T14:20:00Z',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BREAK_OVERLAP')
            ->assertJsonPath('error.conflicts.0.running', true);
    }

    /**
     * THE regression test for the overlap guard.
     *
     * The existing break belongs to work day 2026-09-15 (it started at 23:00,
     * before the 06:00 cutoff rolls the day). The new entry at 02:00 belongs to
     * 2026-09-16. They are on DIFFERENT work days and still overlap in real
     * time. Anyone who "optimises" BreakOverlapGuard to filter by work_date
     * breaks exactly this.
     */
    public function test_overlap_is_detected_across_the_work_day_boundary(): void
    {
        CarbonImmutable::setTestNow('2026-09-16T12:00:00Z');

        $this->manual([
            'started_at' => '2026-09-15T23:00:00Z',
            'ended_at' => '2026-09-16T07:00:00Z',
        ])->assertCreated()->assertJsonPath('data.work_date', '2026-09-15');

        $this->manual([
            'break_type_id' => $this->typeId('smoking'),
            'started_at' => '2026-09-16T02:00:00Z',
            'ended_at' => '2026-09-16T02:10:00Z',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BREAK_OVERLAP');
    }

    /**
     * Half-open intervals: ending one break and starting the next at the same
     * instant is a normal thing to do, not a conflict.
     */
    public function test_two_breaks_that_touch_end_to_start_do_not_overlap(): void
    {
        $this->manual()->assertCreated();

        $this->manual([
            'break_type_id' => $this->typeId('smoking'),
            'started_at' => '2026-09-15T14:13:00Z',
            'ended_at' => '2026-09-15T14:20:00Z',
        ])->assertCreated();

        $this->assertDatabaseCount('break_entries', 2);
    }

    public function test_an_excluded_type_snapshots_its_flag_onto_the_entry(): void
    {
        $this->manual(['break_type_id' => $this->typeId('general_manager')])
            ->assertCreated()
            ->assertJsonPath('data.counts_toward_limit', false);
    }

    // ------------------------------------------------------------------
    // Editing. Overwrites in place - there is no revision history by design.
    // ------------------------------------------------------------------

    public function test_editing_the_start_across_the_cutoff_recomputes_the_work_date(): void
    {
        CarbonImmutable::setTestNow('2026-09-16T12:00:00Z');

        $id = $this->manual([
            'started_at' => '2026-09-16T07:00:00Z',
            'ended_at' => '2026-09-16T07:10:00Z',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/breaks/{$id}", ['started_at' => '2026-09-16T05:00:00Z'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.work_date', '2026-09-15')
            // Duration follows the new start against the unchanged end:
            // 05:00 to 07:10 is 2h10m.
            ->assertJsonPath('data.duration_seconds', 7800);
    }

    public function test_editing_does_not_overlap_with_itself(): void
    {
        $id = $this->manual()->assertCreated()->json('data.id');

        $this->postJson("/api/v1/breaks/{$id}", ['ended_at' => '2026-09-15T14:30:00Z'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.duration_seconds', 1680);
    }

    public function test_editing_a_break_onto_another_is_rejected(): void
    {
        $first = $this->manual()->assertCreated()->json('data.id');

        $this->manual([
            'break_type_id' => $this->typeId('smoking'),
            'started_at' => '2026-09-15T15:00:00Z',
            'ended_at' => '2026-09-15T15:10:00Z',
        ])->assertCreated();

        $this->postJson("/api/v1/breaks/{$first}", ['ended_at' => '2026-09-15T15:05:00Z'], $this->headers())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BREAK_OVERLAP');
    }

    public function test_switching_to_custom_other_without_a_label_is_rejected(): void
    {
        $id = $this->manual()->assertCreated()->json('data.id');

        $this->postJson("/api/v1/breaks/{$id}", ['break_type_id' => $this->typeId('other')], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BREAK_CUSTOM_LABEL_REQUIRED');
    }

    public function test_switching_away_from_custom_other_clears_the_label(): void
    {
        $id = $this->manual([
            'break_type_id' => $this->typeId('other'),
            'other_label' => 'Waiting for a delivery',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/breaks/{$id}", [
            'break_type_id' => $this->typeId('coffee_break'),
            'other_label' => null,
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.other_label', null)
            ->assertJsonPath('data.label', 'Coffee break');
    }

    public function test_editing_updates_the_counted_flag_when_the_type_changes(): void
    {
        $id = $this->manual()->assertCreated()->json('data.id');

        $this->postJson("/api/v1/breaks/{$id}", ['break_type_id' => $this->typeId('maintenance')], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.counts_toward_limit', false);
    }

    public function test_deleting_a_break_removes_it(): void
    {
        $id = $this->manual()->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/breaks/{$id}", [], $this->headers())->assertNoContent();

        $this->assertDatabaseCount('break_entries', 0);
    }

    public function test_the_index_filters_by_work_date_range(): void
    {
        CarbonImmutable::setTestNow('2026-09-16T12:00:00Z');

        $this->manual(['started_at' => '2026-09-15T14:02:00Z', 'ended_at' => '2026-09-15T14:13:00Z'])->assertCreated();
        $this->manual([
            'break_type_id' => $this->typeId('smoking'),
            'started_at' => '2026-09-16T09:02:00Z',
            'ended_at' => '2026-09-16T09:13:00Z',
        ])->assertCreated();

        $response = $this->getJson('/api/v1/breaks?from=2026-09-16&to=2026-09-16', $this->headers())->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Smoking', $response->json('data.0.label'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }
}
