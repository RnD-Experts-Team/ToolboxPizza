<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Models\TicketSection;
use App\Models\ToolboxOutboxEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTicketWorld;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * The seam to NotificationsPizza.
 *
 * There is no HTTP endpoint anywhere in the estate that creates a notification -
 * publishing the envelope IS the creation. So these assertions are on outbox
 * rows, which is the whole of what this service promises.
 *
 * The rule under test throughout: THE ACTOR IS NEVER TOLD ABOUT THEIR OWN
 * ACTION, and nobody without the store is ever told at all.
 */
class TicketNotifierTest extends TestCase
{
    use BuildsTicketWorld, FakesAuthServer, RefreshDatabase;

    private User $reporter;

    private User $assignee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildTicketWorld();

        $this->assignee = $this->makeAssignee(41);

        $this->reporter = $this->makeUser(40, 'Reporter');
        $this->grantStore($this->reporter);
        $this->fakeAuthServer($this->reporter);

        config([
            'toolbox.tickets.notifications.enabled' => true,
            // Off, so a broadcast row can never be mistaken for a notification.
            'toolbox.tickets.realtime.enabled' => false,
            'toolbox.tickets.action_url' => '/toolbox/tickets',
        ]);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/stores/{$this->store->store_number}/tickets{$suffix}";
    }

    private function raise(?string $sectionKey = null): Ticket
    {
        $response = $this->postJson($this->url(), [
            'section_key' => $sectionKey ?? $this->section->key,
            'title' => 'Printer is jammed',
            'description' => 'Again.',
        ], $this->headers())->assertCreated();

        return Ticket::query()->findOrFail($response->json('data.id'));
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(string $subject = 'notifications.v1.notification.send'): array
    {
        return ToolboxOutboxEvent::query()->where('subject', $subject)
            ->orderByDesc('id')->firstOrFail()->payload['data'];
    }

    /**
     * @return array<int, int>
     */
    private function recipients(string $subject = 'notifications.v1.notification.send'): array
    {
        $ids = array_map(fn (array $u) => (int) $u['id'], $this->envelope($subject)['users']);
        sort($ids);

        return $ids;
    }

    /**
     * Http::fake() merges stubs and the first match wins, so the auth server can
     * only be faked once per test - swapping the user it answers with is how a
     * test changes actor mid-way.
     */
    private function actAs(User $user): void
    {
        $this->authUser = $user;
    }

    // ---- who hears about it --------------------------------------------

    public function test_raising_a_ticket_tells_the_resolved_assignees(): void
    {
        $this->raise();

        $this->assertSame([$this->assignee->id], $this->recipients());
        $this->assertSame(['web'], $this->envelope()['channels']);
    }

    /**
     * The actor is the reporter here, so they must be absent from their own
     * creation event - a notification saying "you raised a ticket" is noise.
     */
    public function test_the_reporter_is_not_told_about_their_own_ticket(): void
    {
        $this->raise();

        $this->assertNotContains($this->reporter->id, $this->recipients());
    }

    /**
     * Assigned to the section, but holding a different store. The section is not
     * the rule; the section AND the store is.
     */
    public function test_an_assignee_without_the_store_is_not_told(): void
    {
        $elsewhere = $this->makeUser(42, 'Elsewhere');
        $this->assignSection($elsewhere);
        $this->grantStore($elsewhere, $this->otherStore->store_number);

        $this->raise();

        $this->assertSame([$this->assignee->id], $this->recipients());
    }

    /**
     * A configuration gap must not cost somebody their report: the ticket is
     * created and simply reaches nobody, flagged in the response.
     */
    public function test_a_section_nobody_owns_produces_a_ticket_and_no_envelope(): void
    {
        TicketSection::query()->create(['key' => 'unowned.page', 'name' => 'Unowned']);

        $this->postJson($this->url(), [
            'section_key' => 'unowned.page',
            'title' => 'Nobody is listening',
            'description' => 'Still worth recording.',
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.warnings', ['no_recipients']);

        $this->assertDatabaseCount('toolbox_outbox_events', 0);
    }

    public function test_nothing_is_published_while_notifications_are_disabled(): void
    {
        config(['toolbox.tickets.notifications.enabled' => false]);

        $this->raise();

        $this->assertDatabaseCount('toolbox_outbox_events', 0);
    }

    // ---- the conversation ----------------------------------------------

    /**
     * After creation the reporter is following it, so a reply reaches them -
     * unlike creation, where they were the actor.
     */
    public function test_a_reply_tells_the_reporter_and_not_its_author(): void
    {
        $ticket = $this->raise();

        $this->actAs($this->assignee);
        $this->postJson($this->url("/{$ticket->id}/responses"), ['body' => 'Looking at it now'], $this->headers())
            ->assertCreated();

        $this->assertSame([$this->reporter->id], $this->recipients());

        $payload = $this->envelope()['users'][0]['data'];
        $this->assertSame('ticket_responded', $payload['type']);
        $this->assertStringContainsString('Looking at it now', $payload['body']);
    }

    /**
     * The anchor is what makes the bell useful: it lands on the reply, not at
     * the top of a long ticket.
     */
    public function test_a_reply_links_to_the_reply(): void
    {
        $ticket = $this->raise();

        $this->actAs($this->assignee);
        $id = $this->postJson($this->url("/{$ticket->id}/responses"), ['body' => 'hello'], $this->headers())
            ->assertCreated()->json('data.id');

        $this->assertSame(
            "/toolbox/tickets/{$ticket->id}#response-{$id}",
            $this->envelope()['users'][0]['data']['action_url'],
        );
    }

    // ---- status ---------------------------------------------------------

    /**
     * Fixed, closed and reopened read differently to the person getting them, so
     * they are not collapsed into one "status changed".
     */
    public function test_each_terminal_transition_carries_its_own_type(): void
    {
        $ticket = $this->raise();
        $this->actAs($this->assignee);

        $this->postJson($this->url("/{$ticket->id}/status"), ['status' => 'fixed'], $this->headers())->assertOk();
        $this->assertSame('ticket_fixed', $this->envelope()['users'][0]['data']['type']);

        $this->postJson($this->url("/{$ticket->id}/reopen"), ['reason' => 'Still jammed'], $this->headers())->assertOk();
        $this->assertSame('ticket_reopened', $this->envelope()['users'][0]['data']['type']);

        $this->postJson($this->url("/{$ticket->id}/status"), ['status' => 'closed'], $this->headers())->assertOk();
        $this->assertSame('ticket_closed', $this->envelope()['users'][0]['data']['type']);
    }

    // ---- participants ---------------------------------------------------

    /**
     * The one event with an audience of exactly one person: everybody else on
     * the ticket does not care that somebody was added.
     */
    public function test_adding_a_participant_tells_only_that_person(): void
    {
        $ticket = $this->raise();
        $reader = $this->makeUser(43, 'Reader');

        $this->actAs($this->assignee);
        $this->postJson($this->url("/{$ticket->id}/participants"), [
            'user_id' => $reader->id, 'role' => 'reader',
        ], $this->headers())->assertCreated();

        $this->assertSame([$reader->id], $this->recipients());
        $this->assertSame('ticket_participant_added', $this->envelope()['users'][0]['data']['type']);
    }

    // ---- the audit trail ------------------------------------------------

    /**
     * Assignment resolves dynamically, so who was told moves with the org chart.
     * Recording the list on the domain event is what keeps the history honest
     * after a re-org that would resolve differently today.
     */
    public function test_the_domain_event_records_who_was_told_at_the_time(): void
    {
        $ticket = $this->raise();

        $domain = ToolboxOutboxEvent::query()->where('subject', 'toolbox.v1.ticket.created')->firstOrFail();

        $this->assertSame([$this->assignee->id], $domain->payload['data']['recipient_user_ids']);
        $this->assertSame($ticket->id, $domain->payload['data']['ticket_id']);
        $this->assertSame($this->store->store_number, $domain->payload['data']['store_number']);
        $this->assertSame($this->section->key, $domain->payload['data']['section_key']);
    }

    /**
     * One envelope carrying every recipient, not one envelope per person - the
     * shape NotificationsPizza's NotificationSendHandler expects.
     */
    public function test_two_recipients_share_one_envelope(): void
    {
        $second = $this->makeAssignee(44);

        $this->raise();

        $this->assertSame(1, ToolboxOutboxEvent::query()->where('subject', 'notifications.v1.notification.send')->count());
        $this->assertSame([$this->assignee->id, $second->id], $this->recipients());
    }

    public function test_the_envelope_is_unpublished_and_carries_the_service_source(): void
    {
        $this->raise();

        $row = ToolboxOutboxEvent::query()->where('subject', 'notifications.v1.notification.send')->firstOrFail();

        $this->assertNull($row->published_at);
        $this->assertSame('toolbox-system', $row->payload['source']);
    }
}
