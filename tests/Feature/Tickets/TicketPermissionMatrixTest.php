<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketParticipantRole;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketParticipant;
use App\Models\TicketSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTicketWorld;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * Every cell of the permission matrix, with the exact status code.
 *
 * The one rule worth restating: VIEW is the only ability that 404s. A 403 there
 * would confirm the ticket exists, which is enough to probe for them. Once
 * somebody can see a ticket, refusing an action with 403 is honest.
 */
class TicketPermissionMatrixTest extends TestCase
{
    use BuildsTicketWorld, FakesAuthServer, RefreshDatabase;

    private Ticket $ticket;

    private User $reporter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildTicketWorld();

        // Somebody else raised it; the caller's role varies per test.
        $this->reporter = $this->makeUser(50, 'Reporter');

        $this->ticket = Ticket::factory()->create([
            'store_id' => $this->store->id,
            'ticket_section_id' => $this->section->id,
            'reported_by' => $this->reporter->id,
        ]);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/stores/{$this->store->store_number}/tickets/{$this->ticket->id}{$suffix}";
    }

    /** Become a user with no relationship to the ticket beyond store access. */
    private function actAsStranger(): User
    {
        $user = $this->makeUser(60, 'Stranger');
        $this->grantStore($user);
        $this->fakeAuthServer($user);

        return $user;
    }

    private function actAsReporter(): User
    {
        $this->fakeAuthServer($this->reporter);

        return $this->reporter;
    }

    private function actAsAssignee(): User
    {
        $user = $this->makeUser(61, 'Assignee');
        $this->assignSection($user);
        $this->grantStore($user);
        $this->fakeAuthServer($user);

        return $user;
    }

    private function actAsParticipant(TicketParticipantRole $role): User
    {
        $user = $this->makeUser(62, 'Participant');
        TicketParticipant::query()->create([
            'ticket_id' => $this->ticket->id, 'user_id' => $user->id, 'role' => $role,
        ]);
        $this->fakeAuthServer($user);

        return $user;
    }

    // ---- view ----------------------------------------------------------

    /**
     * Store access alone is NOT a relationship to the ticket. 404, not 403.
     */
    public function test_a_stranger_with_store_access_cannot_even_see_it(): void
    {
        $this->actAsStranger();

        $this->getJson($this->url(), $this->headers())->assertStatus(404);
    }

    public function test_the_reporter_can_see_it(): void
    {
        $this->actAsReporter();

        $this->getJson($this->url(), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.viewer.role', 'reporter');
    }

    public function test_an_assignee_can_see_it(): void
    {
        $this->actAsAssignee();

        $this->getJson($this->url(), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.viewer.role', 'assignee');
    }

    public function test_an_explicit_reader_can_see_it(): void
    {
        $this->actAsParticipant(TicketParticipantRole::Reader);

        $this->getJson($this->url(), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.viewer.role', 'reader')
            ->assertJsonPath('data.viewer.can.respond', false);
    }

    // ---- respond -------------------------------------------------------

    public function test_the_reporter_can_respond(): void
    {
        $this->actAsReporter();

        $this->postJson($this->url('/responses'), ['body' => 'Any news?'], $this->headers())
            ->assertCreated();
    }

    public function test_a_reader_cannot_respond(): void
    {
        $this->actAsParticipant(TicketParticipantRole::Reader);

        $this->postJson($this->url('/responses'), ['body' => 'hello'], $this->headers())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'TICKET_FORBIDDEN')
            ->assertJsonPath('error.ability', 'respond');
    }

    public function test_a_responder_can_respond(): void
    {
        $this->actAsParticipant(TicketParticipantRole::Responder);

        $this->postJson($this->url('/responses'), ['body' => 'on it'], $this->headers())
            ->assertCreated();
    }

    // ---- status --------------------------------------------------------

    /**
     * The reporter follows and replies, but does not decide when their own
     * problem is solved.
     */
    public function test_the_reporter_cannot_change_status(): void
    {
        $this->actAsReporter();

        $this->postJson($this->url('/status'), ['status' => 'fixed'], $this->headers())
            ->assertStatus(403)
            ->assertJsonPath('error.ability', 'change status');
    }

    public function test_a_responder_cannot_change_status(): void
    {
        $this->actAsParticipant(TicketParticipantRole::Responder);

        $this->postJson($this->url('/status'), ['status' => 'fixed'], $this->headers())
            ->assertStatus(403);
    }

    public function test_an_assignee_can_change_status(): void
    {
        $this->actAsAssignee();

        $this->postJson($this->url('/status'), ['status' => 'in_progress'], $this->headers())->assertOk();
    }

    /**
     * The escape hatch: full powers on this one ticket though the section is not
     * theirs and they hold no grant that would ever reach it.
     */
    public function test_an_exception_assignee_can_change_status(): void
    {
        $this->actAsParticipant(TicketParticipantRole::Assignee);

        $this->postJson($this->url('/status'), ['status' => 'in_progress'], $this->headers())
            ->assertOk();
    }

    public function test_a_stranger_changing_status_gets_404_not_403(): void
    {
        $this->actAsStranger();

        // Still 404: they could not see it in the first place, so the ticket's
        // existence must not leak through a different verb.
        $this->postJson($this->url('/status'), ['status' => 'fixed'], $this->headers())->assertStatus(404);
    }

    // ---- participants --------------------------------------------------

    public function test_the_reporter_cannot_add_participants(): void
    {
        $this->actAsReporter();
        $other = $this->makeUser(70);

        // Routing is the queue owner's call.
        $this->postJson($this->url('/participants'), [
            'user_id' => $other->id, 'role' => 'reader',
        ], $this->headers())
            ->assertStatus(403)
            ->assertJsonPath('error.ability', 'manage participants of');
    }

    public function test_an_assignee_can_add_participants(): void
    {
        $this->actAsAssignee();
        $other = $this->makeUser(70);

        $this->postJson($this->url('/participants'), [
            'user_id' => $other->id, 'role' => 'responder',
        ], $this->headers())->assertCreated();
    }

    public function test_adding_the_reporter_as_a_participant_is_redundant(): void
    {
        $this->actAsAssignee();

        $this->postJson($this->url('/participants'), [
            'user_id' => $this->reporter->id, 'role' => 'reader',
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TICKET_PARTICIPANT_REDUNDANT');
    }

    public function test_re_adding_a_participant_changes_their_role(): void
    {
        $this->actAsAssignee();
        $other = $this->makeUser(70);

        $this->postJson($this->url('/participants'), ['user_id' => $other->id, 'role' => 'assignee'], $this->headers())->assertCreated();
        $this->postJson($this->url('/participants'), ['user_id' => $other->id, 'role' => 'reader'], $this->headers())->assertCreated();

        // One row, downgraded - not two grants stacking.
        $this->assertSame(1, TicketParticipant::query()->where('ticket_id', $this->ticket->id)->where('user_id', $other->id)->count());
        $this->assertDatabaseHas('ticket_participants', ['user_id' => $other->id, 'role' => 'reader']);
    }

    // ---- edit ----------------------------------------------------------

    public function test_the_reporter_may_edit_while_it_is_still_pending(): void
    {
        $this->actAsReporter();

        $this->postJson($this->url(), ['title' => 'Clearer title'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.title', 'Clearer title');
    }

    /**
     * Once somebody has picked it up, silently rewriting the description
     * invalidates what they acted on.
     */
    public function test_the_reporter_cannot_edit_once_work_has_started(): void
    {
        $this->ticket->update(['status' => TicketStatus::InProgress]);
        $this->actAsReporter();

        $this->postJson($this->url(), ['title' => 'Too late'], $this->headers())
            ->assertStatus(403)
            ->assertJsonPath('error.ability', 'edit');
    }

    public function test_an_assignee_may_edit_at_any_status(): void
    {
        $this->ticket->update(['status' => TicketStatus::InProgress]);
        $this->actAsAssignee();

        $this->postJson($this->url(), ['title' => 'Retitled'], $this->headers())->assertOk();
    }

    /**
     * Re-sectioning re-routes the ticket to a different audience, so it is an
     * assignee's decision rather than the reporter's - even while the reporter
     * may still fix their own wording.
     */
    public function test_the_reporter_cannot_re_section_the_ticket(): void
    {
        TicketSection::query()->create(['key' => 'other.page', 'name' => 'Other']);
        $this->actAsReporter();

        $this->postJson($this->url(), ['section_key' => 'other.page'], $this->headers())
            ->assertStatus(403)
            ->assertJsonPath('error.ability', 'change status');
    }

    // ---- the highest role wins -----------------------------------------

    /**
     * Being the one who reported it must not demote someone who also owns the
     * queue it landed in.
     */
    public function test_a_reporter_who_is_also_an_assignee_keeps_assignee_powers(): void
    {
        $this->assignSection($this->reporter);
        $this->grantStore($this->reporter);
        $this->actAsReporter();

        $this->getJson($this->url(), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.viewer.role', 'assignee');

        $this->postJson($this->url('/status'), ['status' => 'in_progress'], $this->headers())->assertOk();
    }

    /**
     * An assignment the store filter excludes is no assignment at all.
     */
    public function test_an_assignee_without_the_store_has_no_access(): void
    {
        $user = $this->makeUser(63, 'Elsewhere');
        $this->assignSection($user);
        $this->grantStore($user, $this->otherStore->store_number);
        $this->fakeAuthServer($user);

        $this->getJson($this->url(), $this->headers())->assertStatus(404);
    }
}
