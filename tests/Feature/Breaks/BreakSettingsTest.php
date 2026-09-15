<?php

namespace Tests\Feature\Breaks;

use App\Models\BreakMilestone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

class BreakSettingsTest extends TestCase
{
    use FakesAuthServer, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAuthServer();
    }

    public function test_reading_settings_creates_them_from_the_configured_default(): void
    {
        config(['toolbox.breaks.default_daily_allowance_minutes' => 50]);

        $this->getJson('/api/v1/break-settings', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.daily_allowance_minutes', 50)
            ->assertJsonPath('data.thresholds', [])
            ->assertJsonPath('data.work_day.cutoff_hour', 6)
            ->assertJsonPath('data.work_day.timezone', 'UTC');

        $this->assertDatabaseHas('user_break_settings', [
            'user_id' => $this->authUser->id,
            'daily_allowance_minutes' => 50,
        ]);
    }

    public function test_a_user_sets_their_own_allowance(): void
    {
        $this->postJson('/api/v1/break-settings', ['daily_allowance_minutes' => 30], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.daily_allowance_minutes', 30);

        $this->assertDatabaseHas('user_break_settings', [
            'user_id' => $this->authUser->id,
            'daily_allowance_minutes' => 30,
        ]);
    }

    public function test_an_allowance_of_zero_is_rejected(): void
    {
        $this->postJson('/api/v1/break-settings', ['daily_allowance_minutes' => 0], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('daily_allowance_minutes');
    }

    public function test_milestones_are_deduplicated_and_sorted(): void
    {
        $this->postJson('/api/v1/break-milestones', ['thresholds' => [40, 20, 40, 50]], $this->headers())
            ->assertOk()
            ->assertJsonPath('data', [20, 40, 50]);

        $this->getJson('/api/v1/break-milestones', $this->headers())
            ->assertOk()
            ->assertJsonPath('data', [20, 40, 50]);
    }

    public function test_replacing_the_list_drops_thresholds_left_out_of_it(): void
    {
        $this->postJson('/api/v1/break-milestones', ['thresholds' => [20, 40, 50]], $this->headers())->assertOk();

        $this->postJson('/api/v1/break-milestones', ['thresholds' => [20]], $this->headers())
            ->assertOk()
            ->assertJsonPath('data', [20]);

        $this->assertDatabaseMissing('break_milestones', [
            'user_id' => $this->authUser->id,
            'threshold_minutes' => 40,
        ]);
    }

    public function test_an_empty_list_turns_milestones_off(): void
    {
        $this->postJson('/api/v1/break-milestones', ['thresholds' => [20]], $this->headers())->assertOk();

        $this->postJson('/api/v1/break-milestones', ['thresholds' => []], $this->headers())
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->assertDatabaseCount('break_milestones', 0);
    }

    /**
     * The limit is soft, so "tell me at 70 minutes" is a meaningful request even
     * against a 50-minute budget.
     */
    public function test_a_threshold_above_the_allowance_is_allowed(): void
    {
        $this->postJson('/api/v1/break-settings', ['daily_allowance_minutes' => 50], $this->headers())->assertOk();

        $this->postJson('/api/v1/break-milestones', ['thresholds' => [70]], $this->headers())
            ->assertOk()
            ->assertJsonPath('data', [70]);
    }

    public function test_more_thresholds_than_the_configured_maximum_are_rejected(): void
    {
        config(['toolbox.breaks.max_milestones' => 3]);

        $this->postJson('/api/v1/break-milestones', ['thresholds' => [10, 20, 30, 40]], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('thresholds');
    }

    public function test_a_user_cannot_delete_another_users_milestone(): void
    {
        $other = User::query()->create(['id' => 77, 'name' => 'Sam', 'email' => 'sam@example.com']);
        $theirs = BreakMilestone::query()->create(['user_id' => $other->id, 'threshold_minutes' => 15]);

        $this->deleteJson('/api/v1/break-milestones/'.$theirs->id, [], $this->headers())
            ->assertStatus(404);

        $this->assertDatabaseHas('break_milestones', ['id' => $theirs->id]);
    }
}
