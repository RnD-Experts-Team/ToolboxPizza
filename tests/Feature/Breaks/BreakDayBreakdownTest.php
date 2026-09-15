<?php

namespace Tests\Feature\Breaks;

use App\Models\BreakEntry;
use App\Models\BreakType;
use Carbon\CarbonImmutable;
use Database\Seeders\BreakTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

class BreakDayBreakdownTest extends TestCase
{
    use FakesAuthServer, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAuthServer();
        $this->seed(BreakTypeSeeder::class);

        CarbonImmutable::setTestNow('2026-09-15T21:04:11Z');

        $this->postJson('/api/v1/break-settings', ['daily_allowance_minutes' => 50], $this->headers())->assertOk();
    }

    private function entry(string $slug, string $from, ?string $to): BreakEntry
    {
        $factory = BreakEntry::factory()->for($this->authUser)->ofType($slug)->between($from, $to);

        return $to === null ? $factory->running()->create() : $factory->create();
    }

    private function day(): TestResponse
    {
        return $this->getJson('/api/v1/breaks/day?date=2026-09-15', $this->headers())->assertOk();
    }

    public function test_excluded_types_never_touch_the_allowance(): void
    {
        $this->entry('coffee_break', '2026-09-15T14:02:00Z', '2026-09-15T14:12:00Z');  // 10m counted
        $this->entry('maintenance', '2026-09-15T15:00:00Z', '2026-09-15T15:30:00Z');   // 30m excluded

        $this->day()
            ->assertJsonPath('data.counted_minutes', 10)
            ->assertJsonPath('data.excluded_minutes', 30)
            ->assertJsonPath('data.total_minutes', 40)
            ->assertJsonPath('data.remaining_minutes', 40)
            ->assertJsonPath('data.over_minutes', 0)
            ->assertJsonPath('data.over_limit', false);
    }

    public function test_overage_is_reported_rather_than_blocked(): void
    {
        $this->entry('smoking', '2026-09-15T14:00:00Z', '2026-09-15T14:54:00Z'); // 54m

        $this->day()
            ->assertJsonPath('data.counted_minutes', 54)
            ->assertJsonPath('data.over_minutes', 4)
            ->assertJsonPath('data.over_limit', true)
            ->assertJsonPath('data.remaining_minutes', 0);
    }

    public function test_the_breakdown_splits_counted_and_excluded_categories(): void
    {
        $this->entry('coffee_break', '2026-09-15T14:00:00Z', '2026-09-15T14:11:00Z');
        $this->entry('coffee_break', '2026-09-15T16:00:00Z', '2026-09-15T16:16:00Z');
        $this->entry('general_manager', '2026-09-15T17:05:00Z', '2026-09-15T17:20:00Z');

        $categories = $this->day()->json('data.categories');

        // Counted first, then longest first.
        $this->assertSame('Coffee break', $categories[0]['label']);
        $this->assertSame(2, $categories[0]['entry_count']);
        $this->assertSame(27, $categories[0]['minutes']);
        $this->assertTrue($categories[0]['counts_toward_limit']);

        $this->assertSame('General Manager', $categories[1]['label']);
        $this->assertSame(15, $categories[1]['minutes']);
        $this->assertFalse($categories[1]['counts_toward_limit']);
    }

    public function test_custom_other_breaks_are_grouped_by_their_free_text(): void
    {
        BreakEntry::factory()->for($this->authUser)->ofType('other')
            ->between('2026-09-15T14:00:00Z', '2026-09-15T14:05:00Z')
            ->create(['other_label' => 'Waiting for a delivery']);

        BreakEntry::factory()->for($this->authUser)->ofType('other')
            ->between('2026-09-15T15:00:00Z', '2026-09-15T15:07:00Z')
            ->create(['other_label' => 'Helping a customer']);

        $labels = array_column($this->day()->json('data.categories'), 'label');

        $this->assertContains('Waiting for a delivery', $labels);
        $this->assertContains('Helping a customer', $labels);
    }

    /**
     * The catalog flag is snapshotted onto the entry at write time, so a day
     * already summarised - and possibly already sent - cannot be rewritten by a
     * later reclassification.
     */
    public function test_a_reclassified_type_does_not_rewrite_an_existing_entry(): void
    {
        $entry = $this->entry('general_manager', '2026-09-15T15:00:00Z', '2026-09-15T15:30:00Z');

        BreakType::query()->whereKey($entry->break_type_id)
            ->update(['counts_toward_limit' => true]);

        $this->day()
            ->assertJsonPath('data.counted_minutes', 0)
            ->assertJsonPath('data.excluded_minutes', 30);
    }

    public function test_a_running_break_counts_toward_the_day_as_of_now(): void
    {
        $this->entry('coffee_break', '2026-09-15T20:50:00Z', null);

        // now is 21:04:11, so 14 minutes and change have elapsed.
        $this->day()
            ->assertJsonPath('data.has_active_break', true)
            ->assertJsonPath('data.counted_seconds', 851)
            ->assertJsonPath('data.counted_minutes', 14);
    }

    /**
     * Floors of parts do not sum to the floor of the whole. Documented on
     * purpose so nobody "fixes" the renderer by adding up its own column.
     */
    public function test_entry_minutes_need_not_sum_to_the_day_total(): void
    {
        $this->entry('coffee_break', '2026-09-15T14:00:00Z', '2026-09-15T14:01:30Z');
        $this->entry('coffee_break', '2026-09-15T15:00:00Z', '2026-09-15T15:01:30Z');
        $this->entry('coffee_break', '2026-09-15T16:00:00Z', '2026-09-15T16:01:30Z');

        $response = $this->day();

        $this->assertSame([1, 1, 1], array_column($response->json('data.entries'), 'duration_minutes'));
        $this->assertSame(270, $response->json('data.counted_seconds'));
        $this->assertSame(4, $response->json('data.counted_minutes'));
    }

    public function test_the_day_defaults_to_the_current_work_day(): void
    {
        $this->entry('coffee_break', '2026-09-15T20:00:00Z', '2026-09-15T20:10:00Z');

        $this->getJson('/api/v1/breaks/day', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.work_date', '2026-09-15')
            ->assertJsonPath('data.entry_count', 1);
    }

    public function test_a_day_with_no_breaks_returns_zeros_not_a_404(): void
    {
        $this->getJson('/api/v1/breaks/day?date=2026-09-10', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.entry_count', 0)
            ->assertJsonPath('data.counted_minutes', 0)
            ->assertJsonPath('data.entries', [])
            ->assertJsonPath('data.categories', []);
    }

    public function test_the_window_is_reported_as_the_half_open_cutoff_range(): void
    {
        $this->day()
            ->assertJsonPath('data.work_day.starts_at', '2026-09-15T06:00:00+00:00')
            ->assertJsonPath('data.work_day.ends_at', '2026-09-16T06:00:00+00:00')
            ->assertJsonPath('data.work_day.cutoff_hour', 6);
    }

    /**
     * A break that ran past the cutoff is filed whole against the day it
     * started - not split, and not counted twice.
     */
    public function test_an_overnight_break_belongs_entirely_to_the_day_it_started(): void
    {
        CarbonImmutable::setTestNow('2026-09-16T12:00:00Z');

        $this->entry('meal_snack', '2026-09-15T23:00:00Z', '2026-09-16T07:00:00Z');

        $this->getJson('/api/v1/breaks/day?date=2026-09-15', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.counted_minutes', 480)
            ->assertJsonPath('data.entry_count', 1);

        $this->getJson('/api/v1/breaks/day?date=2026-09-16', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.entry_count', 0);
    }

    public function test_the_export_adds_the_paste_ready_text(): void
    {
        $this->entry('coffee_break', '2026-09-15T14:02:00Z', '2026-09-15T14:13:00Z');

        $text = $this->getJson('/api/v1/breaks/day/export?date=2026-09-15', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.self_reported', true)
            ->json('data.text');

        $this->assertStringContainsString('Break summary - Tuesday, 15 September 2026', $text);
        $this->assertStringContainsString('COUNTED TOWARD THE 50-MINUTE ALLOWANCE', $text);
        $this->assertStringContainsString('Coffee break', $text);
    }

    public function test_the_day_view_does_not_carry_the_text_block(): void
    {
        $this->day()->assertJsonMissingPath('data.text');
    }

    /**
     * Exporting a past day must never create firing rows - a historical day is
     * strictly read-only.
     */
    public function test_exporting_a_past_day_evaluates_nothing(): void
    {
        $this->postJson('/api/v1/break-milestones', ['thresholds' => [1]], $this->headers())->assertOk();

        CarbonImmutable::setTestNow('2026-09-20T12:00:00Z');

        BreakEntry::factory()->for($this->authUser)->ofType('coffee_break')
            ->between('2026-09-15T14:00:00Z', '2026-09-15T14:30:00Z')
            ->create();

        $this->getJson('/api/v1/breaks/day/export?date=2026-09-15', $this->headers())->assertOk();

        $this->assertDatabaseCount('break_milestone_firings', 0);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }
}
