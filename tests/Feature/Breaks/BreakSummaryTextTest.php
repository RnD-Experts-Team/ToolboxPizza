<?php

namespace Tests\Feature\Breaks;

use App\Models\BreakEntry;
use App\Models\User;
use App\Services\Breaks\BreakMilestoneEvaluator;
use Carbon\CarbonImmutable;
use Database\Seeders\BreakTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * The export is a document a person sends to their manager, so its layout is
 * part of the contract and is asserted byte for byte. Changing the rendering is
 * fine - change it here in the same commit.
 */
class BreakSummaryTextTest extends TestCase
{
    use FakesAuthServer, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAuthServer(User::query()->create([
            'id' => 9, 'name' => 'Dana Whitfield', 'email' => 'dana@example.com',
        ]));
        $this->seed(BreakTypeSeeder::class);

        CarbonImmutable::setTestNow('2026-09-15T21:04:11Z');

        $this->postJson('/api/v1/break-settings', ['daily_allowance_minutes' => 50], $this->headers())->assertOk();
        $this->postJson('/api/v1/break-milestones', ['thresholds' => [20, 40]], $this->headers())->assertOk();
    }

    private function entry(string $slug, string $from, ?string $to): BreakEntry
    {
        $factory = BreakEntry::factory()->for($this->authUser)->ofType($slug)->between($from, $to);

        return $to === null ? $factory->running()->create() : $factory->create();
    }

    private function text(): string
    {
        return $this->getJson('/api/v1/breaks/day/export?date=2026-09-15', $this->headers())
            ->assertOk()
            ->json('data.text');
    }

    public function test_a_full_day_renders_exactly(): void
    {
        $coffee = $this->entry('coffee_break', '2026-09-15T14:02:00Z', '2026-09-15T14:13:00Z');
        $coffee->notes()->create(['body' => 'machine was down, waited', 'created_by' => $this->authUser->id]);

        $this->entry('general_manager', '2026-09-15T15:00:00Z', '2026-09-15T15:30:00Z');
        $this->entry('rest_room', '2026-09-15T16:30:00Z', '2026-09-15T16:39:00Z');
        $this->entry('management', '2026-09-15T17:05:00Z', '2026-09-15T17:20:00Z');

        $smoking = $this->entry('smoking', '2026-09-15T18:10:00Z', '2026-09-15T18:44:00Z');
        $smoking->notes()->create(['body' => 'stepped out with the delivery driver', 'created_by' => $this->authUser->id]);

        app(BreakMilestoneEvaluator::class)->evaluate($this->authUser, '2026-09-15');

        $expected = <<<'TEXT'
        Break summary - Tuesday, 15 September 2026
        Dana Whitfield · self-reported · generated 2026-09-15 21:04 UTC

        COUNTED TOWARD THE 50-MINUTE ALLOWANCE
          14:02-14:13    11m   Coffee break
          16:30-16:39     9m   Rest room
          18:10-18:44    34m   Smoking
          ------------------------------------------------
          Counted total         54m of 50m      -  4m OVER

        NOT COUNTED (special breaks)
          15:00-15:30    30m   General Manager
          17:05-17:20    15m   Management
          ------------------------------------------------
          Excluded total        45m

        BY CATEGORY
          Smoking                 34m   counted
          Coffee break            11m   counted
          Rest room                9m   counted
          General Manager         30m   excluded
          Management              15m   excluded

        NOTES
          14:02  Coffee break   "machine was down, waited"
          18:10  Smoking        "stepped out with the delivery driver"

        Milestones reached: 20m, 40m, allowance 50m

        Work day runs 06:00-06:00 UTC. All times UTC.
        TEXT;

        $this->assertSame($expected, $this->text());
    }

    public function test_an_under_allowance_day_shows_minutes_left_rather_than_over(): void
    {
        $this->entry('coffee_break', '2026-09-15T14:00:00Z', '2026-09-15T14:11:00Z');

        $this->assertStringContainsString('11m of 50m      -  39m left', $this->text());
    }

    public function test_a_manual_entry_is_called_out_in_the_document(): void
    {
        BreakEntry::factory()->for($this->authUser)->ofType('smoking')->manual()
            ->between('2026-09-15T15:00:00Z', '2026-09-15T15:06:00Z')
            ->create();

        $this->assertStringContainsString('15:00-15:06     6m   Smoking [manual]', $this->text());
    }

    public function test_a_running_break_is_marked_and_the_totals_are_dated(): void
    {
        $this->entry('coffee_break', '2026-09-15T20:50:00Z', null);

        $text = $this->text();

        $this->assertStringContainsString('20:50-...      14m   Coffee break (running)', $text);
        $this->assertStringContainsString('One break is still running; totals are as of 21:04 UTC.', $text);
    }

    public function test_a_custom_other_break_is_labelled_by_its_free_text(): void
    {
        BreakEntry::factory()->for($this->authUser)->ofType('other')
            ->between('2026-09-15T14:00:00Z', '2026-09-15T14:08:00Z')
            ->create(['other_label' => 'Waiting for a delivery']);

        $this->assertStringContainsString('Waiting for a delivery', $this->text());
    }

    public function test_an_empty_day_still_renders_a_usable_document(): void
    {
        $text = $this->getJson('/api/v1/breaks/day/export?date=2026-09-14', $this->headers())
            ->assertOk()
            ->json('data.text');

        $this->assertStringContainsString('Break summary - Monday, 14 September 2026', $text);
        $this->assertStringContainsString('  (none)', $text);
        $this->assertStringContainsString('0m of 50m      -  50m left', $text);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }
}
