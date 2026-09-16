<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTicketWorld;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * The store in the path is a boundary, not a label.
 *
 * A ticket id belonging to store A must not resolve under store B's URL EVEN
 * when the caller legitimately holds both stores - otherwise the path stops
 * describing what is being acted on, and the per-store rule pizzasys applies to
 * that path stops meaning anything.
 */
class TicketStoreScopeTest extends TestCase
{
    use BuildsTicketWorld, FakesAuthServer, RefreshDatabase;

    private User $reporter;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->buildTicketWorld();

        $this->reporter = $this->makeUser(40, 'Reporter');
        // Both stores, deliberately: the 404 below must come from the path
        // scope, not from a missing grant.
        $this->grantStore($this->reporter);
        $this->grantStore($this->reporter, $this->otherStore->store_number);
        $this->fakeAuthServer($this->reporter);

        config([
            'toolbox.tickets.notifications.enabled' => false,
            'toolbox.tickets.realtime.enabled' => false,
        ]);

        $this->ticket = Ticket::factory()->create([
            'store_id' => $this->store->id,
            'ticket_section_id' => $this->section->id,
            'reported_by' => $this->reporter->id,
        ]);
    }

    private function under(string $storeNumber, string $suffix = ''): string
    {
        return "/api/v1/stores/{$storeNumber}/tickets{$suffix}";
    }

    // ---- the boundary -----------------------------------------------------

    public function test_a_ticket_is_visible_under_its_own_store(): void
    {
        $this->getJson($this->under($this->store->store_number, "/{$this->ticket->id}"), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.id', $this->ticket->id);
    }

    public function test_the_same_ticket_is_a_404_under_another_store(): void
    {
        $this->getJson($this->under($this->otherStore->store_number, "/{$this->ticket->id}"), $this->headers())
            ->assertStatus(404);
    }

    /**
     * Every verb, not just the read: a scope enforced on GET and forgotten on
     * POST is not a scope.
     */
    public function test_every_sub_route_is_scoped_to_the_store_in_the_path(): void
    {
        $wrong = $this->otherStore->store_number;
        $id = $this->ticket->id;

        $this->postJson($this->under($wrong, "/{$id}"), ['title' => 'Moved'], $this->headers())->assertStatus(404);
        $this->postJson($this->under($wrong, "/{$id}/status"), ['status' => 'fixed'], $this->headers())->assertStatus(404);
        $this->postJson($this->under($wrong, "/{$id}/reopen"), ['reason' => 'x'], $this->headers())->assertStatus(404);
        $this->postJson($this->under($wrong, "/{$id}/responses"), ['body' => 'x'], $this->headers())->assertStatus(404);
        $this->postJson($this->under($wrong, "/{$id}/notes"), ['body' => 'x'], $this->headers())->assertStatus(404);
        // A real file, so the request gets past validation and actually
        // reaches the store scope rather than stopping at a 422.
        $this->post($this->under($wrong, "/{$id}/attachments"), [
            'files' => [UploadedFile::fake()->image('x.jpg')],
        ], $this->headers())->assertStatus(404);
        $this->getJson($this->under($wrong, "/{$id}/participants"), $this->headers())->assertStatus(404);
        $this->getJson($this->under($wrong, "/{$id}/recipients"), $this->headers())->assertStatus(404);
    }

    public function test_the_store_index_lists_only_that_stores_tickets(): void
    {
        $elsewhere = Ticket::factory()->create([
            'store_id' => $this->otherStore->id,
            'ticket_section_id' => $this->section->id,
            'reported_by' => $this->reporter->id,
        ]);

        $here = $this->getJson($this->under($this->store->store_number), $this->headers())->assertOk();
        $there = $this->getJson($this->under($this->otherStore->store_number), $this->headers())->assertOk();

        $this->assertSame([$this->ticket->id], array_column($here->json('data'), 'id'));
        $this->assertSame([$elsewhere->id], array_column($there->json('data'), 'id'));
    }

    /**
     * A ticket is raised against the store in the PATH, never one named in the
     * body - there is no store field to send.
     */
    public function test_a_ticket_is_raised_against_the_store_in_the_path(): void
    {
        $id = $this->postJson($this->under($this->otherStore->store_number), [
            'section_key' => $this->section->key,
            'title' => 'Raised elsewhere',
            'description' => 'Belongs to store two.',
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.store.store_number', $this->otherStore->store_number)
            ->json('data.id');

        $this->assertSame($this->otherStore->id, (int) Ticket::query()->findOrFail($id)->store_id);
    }

    // ---- the store itself --------------------------------------------------

    /**
     * The tickets analogue of "user not synced yet": the store exists in
     * pizzasys but has not replicated here, which is a different problem from a
     * caller asking for a store that never existed - and both are 404 to them.
     */
    public function test_a_store_that_has_not_replicated_is_a_clear_404(): void
    {
        $this->getJson($this->under('03795-09999'), $this->headers())
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'STORE_NOT_FOUND')
            ->assertJsonPath('error.store_number', '03795-09999');
    }

    /**
     * The design decision this route was named for: pizzasys receives the STORE
     * CODE from the path, the same string the URL shows. Route-model binding
     * would have sent the bound model's integer id instead, and the auth rule
     * would no longer read like the URL it guards.
     */
    public function test_the_store_code_is_what_reaches_the_auth_server(): void
    {
        $this->getJson($this->under($this->store->store_number, "/{$this->ticket->id}"), $this->headers())->assertOk();

        Http::assertSent(function (Request $request) {
            $this->assertSame($this->store->store_number, $request->data()['store_context']['path']['storeId']);
            $this->assertSame('GET', $request->data()['method']);
            $this->assertSame('api.v1.stores.tickets.show', $request->data()['route_name']);

            return true;
        });
    }
}
