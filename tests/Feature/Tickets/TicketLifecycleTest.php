<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketSection;
use App\Models\TicketStatusChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTicketWorld;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

class TicketLifecycleTest extends TestCase
{
    use BuildsTicketWorld, FakesAuthServer, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildTicketWorld();
        $this->fakeAuthServer();

        // The caller is an assignee, so they can move the ticket as well as
        // raise it.
        $this->assignSection($this->authUser);
        $this->grantStore($this->authUser);
    }

    private function raise(): int
    {
        return (int) $this->postJson("/api/v1/stores/{$this->store->store_number}/tickets", [
            'section_key' => 'hiring.page',
            'title' => 'Scanner is offline',
            'description' => 'The handheld will not connect.',
        ], $this->headers())->assertCreated()->json('data.id');
    }

    private function setStatus(int $id, string $status, ?string $reason = null): TestResponse
    {
        return $this->postJson(
            "/api/v1/stores/{$this->store->store_number}/tickets/{$id}/status",
            array_filter(['status' => $status, 'reason' => $reason]),
            $this->headers(),
        );
    }

    public function test_a_ticket_is_raised_as_pending_with_an_audit_row_from_birth(): void
    {
        $id = $this->raise();

        $this->assertDatabaseHas('tickets', [
            'id' => $id,
            'status' => 'pending',
            'store_id' => $this->store->id,
            'reported_by' => $this->authUser->id,
        ]);

        // The audit is complete from the start, not from the first transition.
        $this->assertDatabaseHas('ticket_status_changes', [
            'ticket_id' => $id,
            'from_status' => null,
            'to_status' => 'pending',
        ]);
    }

    public function test_the_creation_response_reports_who_it_reached(): void
    {
        $this->postJson("/api/v1/stores/{$this->store->store_number}/tickets", [
            'section_key' => 'hiring.page',
            'title' => 'T',
            'description' => 'D',
        ], $this->headers())
            ->assertCreated()
            // The caller is an assignee, so somebody is on it.
            ->assertJsonPath('data.warnings', []);
    }

    /**
     * A section nobody owns still accepts tickets - refusing would throw away
     * someone's report because of an admin's configuration gap.
     */
    public function test_a_ticket_with_no_recipients_is_created_with_a_warning(): void
    {
        $orphan = TicketSection::query()->create(['key' => 'orphan', 'name' => 'Orphan']);

        $this->postJson("/api/v1/stores/{$this->store->store_number}/tickets", [
            'section_key' => $orphan->key,
            'title' => 'T',
            'description' => 'D',
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.warnings', ['no_recipients']);
    }

    public function test_a_retired_section_refuses_new_tickets(): void
    {
        $this->section->update(['active' => false]);

        $this->postJson("/api/v1/stores/{$this->store->store_number}/tickets", [
            'section_key' => 'hiring.page',
            'title' => 'T',
            'description' => 'D',
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TICKET_SECTION_INACTIVE');
    }

    public function test_each_legal_transition_writes_exactly_one_audit_row(): void
    {
        $id = $this->raise();

        $this->setStatus($id, 'in_progress')->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->setStatus($id, 'fixed')->assertOk()->assertJsonPath('data.status', 'fixed');

        // One creation row plus two transitions.
        $this->assertSame(3, TicketStatusChange::query()->where('ticket_id', $id)->count());

        $last = TicketStatusChange::query()->where('ticket_id', $id)->latest('id')->first();
        $this->assertSame(TicketStatus::InProgress, $last->from_status);
        $this->assertSame(TicketStatus::Fixed, $last->to_status);
        $this->assertSame($this->authUser->id, $last->created_by);
    }

    public function test_an_illegal_transition_is_refused_and_writes_nothing(): void
    {
        $id = $this->raise();
        $this->setStatus($id, 'closed')->assertOk();

        $before = TicketStatusChange::query()->where('ticket_id', $id)->count();

        $this->setStatus($id, 'in_progress')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'TICKET_ILLEGAL_TRANSITION')
            // The client is handed what IS possible, so a dead end becomes a
            // set of buttons.
            ->assertJsonPath('error.allowed', ['pending']);

        $this->assertSame($before, TicketStatusChange::query()->where('ticket_id', $id)->count());
        $this->assertDatabaseHas('tickets', ['id' => $id, 'status' => 'closed']);
    }

    public function test_moving_to_the_status_it_already_has_is_refused(): void
    {
        $id = $this->raise();

        $this->setStatus($id, 'pending')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'TICKET_ALREADY_IN_STATUS');
    }

    public function test_fixing_and_closing_stamp_their_timestamps(): void
    {
        $id = $this->raise();

        $this->setStatus($id, 'fixed')->assertOk();
        $this->assertNotNull(Ticket::query()->find($id)->fixed_at);

        $this->setStatus($id, 'closed')->assertOk();
        $this->assertNotNull(Ticket::query()->find($id)->closed_at);
    }

    public function test_reopening_clears_the_finished_marks_and_counts(): void
    {
        $id = $this->raise();
        $this->setStatus($id, 'fixed')->assertOk();
        $this->setStatus($id, 'closed')->assertOk();

        $this->postJson("/api/v1/stores/{$this->store->store_number}/tickets/{$id}/reopen", [
            'reason' => 'It came back the next morning.',
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $ticket = Ticket::query()->find($id);

        // A reopened ticket must not still read as finished on any screen
        // sorting by these.
        $this->assertNull($ticket->fixed_at);
        $this->assertNull($ticket->closed_at);
        $this->assertNotNull($ticket->reopened_at);
        $this->assertSame(1, $ticket->reopen_count);

        $this->assertDatabaseHas('ticket_status_changes', [
            'ticket_id' => $id,
            'to_status' => 'pending',
            'is_reopen' => true,
            'reason' => 'It came back the next morning.',
        ]);
    }

    public function test_reopening_without_a_reason_is_refused(): void
    {
        $id = $this->raise();
        $this->setStatus($id, 'fixed')->assertOk();

        $this->postJson("/api/v1/stores/{$this->store->store_number}/tickets/{$id}/reopen", [], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_reopening_a_ticket_that_is_not_finished_is_refused(): void
    {
        $id = $this->raise();

        $this->postJson("/api/v1/stores/{$this->store->store_number}/tickets/{$id}/reopen", [
            'reason' => 'nothing to reopen',
        ], $this->headers())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'TICKET_NOT_REOPENABLE');
    }

    /**
     * Going through /status rather than /reopen still lands on pending, but is
     * NOT flagged a reopen - that flag is what distinguishes a deliberate
     * "this came back" from an ordinary move.
     */
    public function test_the_reopen_edge_via_the_status_endpoint_is_still_flagged(): void
    {
        $id = $this->raise();
        $this->setStatus($id, 'fixed')->assertOk();

        $this->setStatus($id, 'pending', 'still broken')->assertOk();

        $this->assertDatabaseHas('ticket_status_changes', [
            'ticket_id' => $id,
            'to_status' => 'pending',
            'is_reopen' => true,
        ]);
    }

    public function test_the_status_endpoint_refuses_a_reopen_with_no_reason(): void
    {
        $id = $this->raise();
        $this->setStatus($id, 'fixed')->assertOk();

        $this->setStatus($id, 'pending')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TICKET_REOPEN_REASON_REQUIRED');
    }

    public function test_the_show_payload_carries_the_history_and_the_viewers_abilities(): void
    {
        $id = $this->raise();

        $this->getJson("/api/v1/stores/{$this->store->store_number}/tickets/{$id}", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.viewer.role', 'assignee')
            ->assertJsonPath('data.viewer.can.change_status', true)
            ->assertJsonCount(1, 'data.status_changes');
    }
}
