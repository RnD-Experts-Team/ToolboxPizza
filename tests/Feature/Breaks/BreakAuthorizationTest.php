<?php

namespace Tests\Feature\Breaks;

use App\Models\BreakEntry;
use App\Models\BreakType;
use App\Models\User;
use Database\Seeders\BreakTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * Another user's break answers 404, never 403. A 403 would confirm the id
 * exists, which is enough to probe the table.
 */
class BreakAuthorizationTest extends TestCase
{
    use FakesAuthServer, RefreshDatabase;

    private BreakEntry $theirBreak;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAuthServer();
        $this->seed(BreakTypeSeeder::class);

        $other = User::query()->create(['id' => 77, 'name' => 'Sam', 'email' => 'sam@example.com']);

        $this->theirBreak = BreakEntry::factory()
            ->for($other)
            ->between('2026-09-15T10:00:00Z', '2026-09-15T10:10:00Z')
            ->create();
    }

    public function test_a_user_cannot_read_another_users_break(): void
    {
        $this->getJson('/api/v1/breaks/'.$this->theirBreak->id, $this->headers())->assertStatus(404);
    }

    public function test_a_user_cannot_edit_another_users_break(): void
    {
        $this->postJson('/api/v1/breaks/'.$this->theirBreak->id, [
            'started_at' => '2026-09-15T09:00:00Z',
        ], $this->headers())->assertStatus(404);

        $this->assertSame(
            '2026-09-15 10:00:00',
            $this->theirBreak->fresh()->started_at->toDateTimeString(),
        );
    }

    public function test_a_user_cannot_stop_another_users_break(): void
    {
        $running = BreakEntry::factory()
            ->for(User::query()->find(77))
            ->between('2026-09-15T12:00:00Z', null)
            ->running()
            ->create();

        $this->postJson("/api/v1/breaks/{$running->id}/stop", [], $this->headers())->assertStatus(404);

        $this->assertTrue($running->fresh()->isRunning());
    }

    public function test_a_user_cannot_delete_another_users_break(): void
    {
        $this->deleteJson('/api/v1/breaks/'.$this->theirBreak->id, [], $this->headers())->assertStatus(404);

        $this->assertDatabaseHas('break_entries', ['id' => $this->theirBreak->id]);
    }

    public function test_a_user_cannot_annotate_another_users_break(): void
    {
        $this->postJson('/api/v1/breaks/'.$this->theirBreak->id.'/notes', [
            'body' => 'not mine to annotate',
        ], $this->headers())->assertStatus(404);

        $this->assertDatabaseCount('notes', 0);
    }

    public function test_the_index_only_ever_returns_the_callers_own_breaks(): void
    {
        BreakEntry::factory()
            ->for($this->authUser)
            ->ofType('smoking')
            ->between('2026-09-15T11:00:00Z', '2026-09-15T11:05:00Z')
            ->create();

        $response = $this->getJson('/api/v1/breaks', $this->headers())->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Smoking', $response->json('data.0.label'));
    }

    public function test_the_active_endpoint_ignores_another_users_running_break(): void
    {
        BreakEntry::factory()
            ->for(User::query()->find(77))
            ->between('2026-09-15T12:00:00Z', null)
            ->running()
            ->create();

        $this->getJson('/api/v1/breaks/active', $this->headers())
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_a_break_type_id_is_still_required_to_exist(): void
    {
        $this->postJson('/api/v1/breaks/start', ['break_type_id' => 99999], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('break_type_id');

        $this->assertSame(15, BreakType::query()->count());
    }
}
