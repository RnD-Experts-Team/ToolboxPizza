<?php

namespace Tests\Feature\Breaks;

use App\Models\BreakType;
use App\Models\Note;
use Carbon\CarbonImmutable;
use Database\Seeders\BreakTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

class BreakNotesTest extends TestCase
{
    use FakesAuthServer, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAuthServer();
        $this->seed(BreakTypeSeeder::class);

        CarbonImmutable::setTestNow('2026-09-15T14:02:00Z');
    }

    private function typeId(string $slug): int
    {
        return (int) BreakType::query()->where('slug', $slug)->value('id');
    }

    private function startBreak(): int
    {
        return (int) $this->postJson(
            '/api/v1/breaks/start',
            ['break_type_id' => $this->typeId('coffee_break')],
            $this->headers(),
        )->assertCreated()->json('data.id');
    }

    public function test_a_note_can_be_added_while_the_break_is_running(): void
    {
        $id = $this->startBreak();

        $this->postJson("/api/v1/breaks/{$id}/notes", ['body' => 'machine was down, waited'], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.body', 'machine was down, waited')
            ->assertJsonPath('data.creator.id', $this->authUser->id);
    }

    public function test_a_note_can_still_be_added_long_after_the_break_ended(): void
    {
        $id = $this->startBreak();
        $this->postJson("/api/v1/breaks/{$id}/stop", [], $this->headers())->assertOk();

        // The next morning - explaining yesterday's long break is the normal
        // case, not an edge one.
        CarbonImmutable::setTestNow('2026-09-16T09:00:00Z');

        $this->postJson("/api/v1/breaks/{$id}/notes", ['body' => 'covering for Sam'], $this->headers())
            ->assertCreated();
    }

    public function test_a_break_carries_several_notes_in_the_order_they_were_written(): void
    {
        $id = $this->startBreak();

        $this->postJson("/api/v1/breaks/{$id}/notes", ['body' => 'first'], $this->headers())->assertCreated();
        $this->postJson("/api/v1/breaks/{$id}/notes", ['body' => 'second'], $this->headers())->assertCreated();

        $response = $this->getJson("/api/v1/breaks/{$id}", $this->headers())->assertOk();

        $this->assertSame(['first', 'second'], array_column($response->json('data.notes'), 'body'));
    }

    public function test_an_empty_note_is_rejected(): void
    {
        $id = $this->startBreak();

        $this->postJson("/api/v1/breaks/{$id}/notes", ['body' => ''], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }

    public function test_notes_are_not_shared_between_breaks(): void
    {
        $first = $this->startBreak();
        $this->postJson("/api/v1/breaks/{$first}/notes", ['body' => 'only mine'], $this->headers())->assertCreated();
        $this->postJson("/api/v1/breaks/{$first}/stop", [], $this->headers())->assertOk();

        CarbonImmutable::setTestNow('2026-09-15T15:00:00Z');
        $second = $this->startBreak();

        $this->getJson("/api/v1/breaks/{$second}", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.notes', []);
    }

    /**
     * The note table is polymorphic, so there is no foreign key and nothing
     * cascades - the write service has to delete them itself.
     *
     * withTrashed(), and not merely "no live notes remain": Note soft-deletes
     * but BreakEntry does not, so a plain delete() would leave the row behind
     * pointing at a break_entries.id that no longer exists, where breaks:prune
     * could never reach it. The rows have to be GONE.
     */
    public function test_deleting_a_break_hard_deletes_its_notes(): void
    {
        $id = $this->startBreak();
        $this->postJson("/api/v1/breaks/{$id}/notes", ['body' => 'goes away too'], $this->headers())->assertCreated();
        $this->postJson("/api/v1/breaks/{$id}/stop", [], $this->headers())->assertOk();

        $this->deleteJson("/api/v1/breaks/{$id}", [], $this->headers())->assertNoContent();

        $this->assertSame(0, Note::withTrashed()->count());
        $this->assertDatabaseCount('notes', 0);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }
}
