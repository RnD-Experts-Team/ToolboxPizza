<?php

namespace Tests\Feature\Breaks;

use App\Models\BreakType;
use Carbon\CarbonImmutable;
use Database\Seeders\BreakTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

class BreakTimerTest extends TestCase
{
    use FakesAuthServer, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAuthServer();
        $this->seed(BreakTypeSeeder::class);
    }

    private function typeId(string $slug): int
    {
        return (int) BreakType::query()->where('slug', $slug)->value('id');
    }

    public function test_starting_a_break_creates_a_running_entry(): void
    {
        CarbonImmutable::setTestNow('2026-09-15T14:02:00Z');

        $this->postJson('/api/v1/breaks/start', ['break_type_id' => $this->typeId('coffee_break')], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.label', 'Coffee break')
            ->assertJsonPath('data.running', true)
            ->assertJsonPath('data.ended_at', null)
            ->assertJsonPath('data.source', 'timer')
            ->assertJsonPath('data.counts_toward_limit', true)
            ->assertJsonPath('data.work_date', '2026-09-15');
    }

    public function test_starting_a_second_break_while_one_runs_is_rejected(): void
    {
        $this->postJson('/api/v1/breaks/start', ['break_type_id' => $this->typeId('coffee_break')], $this->headers())
            ->assertCreated();

        $this->postJson('/api/v1/breaks/start', ['break_type_id' => $this->typeId('smoking')], $this->headers())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ALREADY_ON_BREAK')
            ->assertJsonPath('error.running.label', 'Coffee break');

        $this->assertDatabaseCount('break_entries', 1);
    }

    public function test_stopping_a_break_records_its_duration_in_seconds(): void
    {
        CarbonImmutable::setTestNow('2026-09-15T14:02:00Z');

        $id = $this->postJson('/api/v1/breaks/start', ['break_type_id' => $this->typeId('coffee_break')], $this->headers())
            ->json('data.id');

        // 9 minutes 29 seconds - deliberately not a whole minute.
        CarbonImmutable::setTestNow('2026-09-15T14:11:29Z');

        $this->postJson("/api/v1/breaks/{$id}/stop", [], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.running', false)
            ->assertJsonPath('data.duration_seconds', 569)
            // Floored, never rounded.
            ->assertJsonPath('data.duration_minutes', 9);
    }

    public function test_stopping_an_already_stopped_break_is_rejected(): void
    {
        $id = $this->postJson('/api/v1/breaks/start', ['break_type_id' => $this->typeId('coffee_break')], $this->headers())
            ->json('data.id');

        $this->postJson("/api/v1/breaks/{$id}/stop", [], $this->headers())->assertOk();

        $this->postJson("/api/v1/breaks/{$id}/stop", [], $this->headers())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BREAK_NOT_RUNNING');
    }

    public function test_the_active_endpoint_is_null_when_nothing_is_running(): void
    {
        $this->getJson('/api/v1/breaks/active', $this->headers())
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_the_active_endpoint_reports_elapsed_time_for_a_running_break(): void
    {
        CarbonImmutable::setTestNow('2026-09-15T14:02:00Z');

        $this->postJson('/api/v1/breaks/start', ['break_type_id' => $this->typeId('coffee_break')], $this->headers())
            ->assertCreated();

        CarbonImmutable::setTestNow('2026-09-15T14:14:30Z');

        $this->getJson('/api/v1/breaks/active', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.running', true)
            ->assertJsonPath('data.duration_seconds', 750)
            ->assertJsonPath('data.duration_minutes', 12)
            ->assertJsonPath('data.belongs_to_previous_work_day', false);
    }

    /**
     * A break that is still running when the 06:00 cutoff passes keeps the work
     * day it started in. The flag lets a client say so rather than silently
     * disagreeing with today's summary, which excludes it.
     */
    public function test_a_break_running_across_the_cutoff_still_reports_its_start_work_day(): void
    {
        CarbonImmutable::setTestNow('2026-09-16T05:50:00Z');

        $this->postJson('/api/v1/breaks/start', ['break_type_id' => $this->typeId('rest_room')], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.work_date', '2026-09-15');

        CarbonImmutable::setTestNow('2026-09-16T06:10:00Z');

        $this->getJson('/api/v1/breaks/active', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.work_date', '2026-09-15')
            ->assertJsonPath('data.belongs_to_previous_work_day', true);
    }

    public function test_a_custom_other_break_requires_a_label(): void
    {
        $this->postJson('/api/v1/breaks/start', ['break_type_id' => $this->typeId('other')], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BREAK_CUSTOM_LABEL_REQUIRED');
    }

    public function test_a_custom_other_break_is_labelled_by_its_free_text(): void
    {
        $this->postJson('/api/v1/breaks/start', [
            'break_type_id' => $this->typeId('other'),
            'other_label' => 'Waiting for a delivery',
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.label', 'Waiting for a delivery')
            ->assertJsonPath('data.other_label', 'Waiting for a delivery');
    }

    public function test_a_catalog_type_refuses_a_custom_label(): void
    {
        $this->postJson('/api/v1/breaks/start', [
            'break_type_id' => $this->typeId('coffee_break'),
            'other_label' => 'not allowed here',
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BREAK_CUSTOM_LABEL_NOT_ALLOWED');
    }

    public function test_a_retired_type_cannot_be_chosen_for_a_new_break(): void
    {
        BreakType::query()->where('slug', 'smoking')->update(['active' => false]);

        $this->postJson('/api/v1/breaks/start', ['break_type_id' => $this->typeId('smoking')], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BREAK_TYPE_INACTIVE');
    }

    /**
     * The limit is soft by design: the system reports, it does not police.
     */
    public function test_a_break_can_still_be_started_after_the_allowance_is_spent(): void
    {
        $this->postJson('/api/v1/break-settings', ['daily_allowance_minutes' => 10], $this->headers())->assertOk();

        CarbonImmutable::setTestNow('2026-09-15T12:00:00Z');
        $this->postJson('/api/v1/breaks', [
            'break_type_id' => $this->typeId('meal_snack'),
            'started_at' => '2026-09-15T10:00:00Z',
            'ended_at' => '2026-09-15T10:45:00Z',
        ], $this->headers())->assertCreated();

        $this->postJson('/api/v1/breaks/start', ['break_type_id' => $this->typeId('coffee_break')], $this->headers())
            ->assertCreated();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }
}
