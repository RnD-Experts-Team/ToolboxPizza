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
 * Live ticket state on the wire.
 *
 * The transport is notifications.v1.broadcast.send - NotificationsPizza's
 * transient path, which reaches private-users.{id} over Reverb WITHOUT writing
 * an InAppNotification row. An open ticket view converges without polling and
 * without filling the bell.
 *
 * Two things here are not just plumbing: the audience INCLUDES the actor (their
 * other tabs need to converge too, which is the opposite of the notifier), and
 * the embedded ticket is viewer-neutral.
 */
class TicketBroadcastTest extends TestCase
{
    use BuildsTicketWorld, FakesAuthServer, RefreshDatabase;

    private const SUBJECT = 'notifications.v1.broadcast.send';

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
            'toolbox.tickets.realtime.enabled' => true,
            // Off, so a notification envelope can never be mistaken for a
            // broadcast - both land in the same table.
            'toolbox.tickets.notifications.enabled' => false,
        ]);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/stores/{$this->store->store_number}/tickets{$suffix}";
    }

    private function raise(): Ticket
    {
        $response = $this->postJson($this->url(), [
            'section_key' => $this->section->key,
            'title' => 'Screen is frozen',
            'description' => 'Third time today.',
        ], $this->headers())->assertCreated();

        return Ticket::query()->findOrFail($response->json('data.id'));
    }

    private function actAs(User $user): void
    {
        $this->authUser = $user;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function broadcasts(): array
    {
        return ToolboxOutboxEvent::query()
            ->where('subject', self::SUBJECT)
            ->orderBy('id')
            ->get()
            ->map(fn (ToolboxOutboxEvent $e) => $e->payload['data'])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function latest(): array
    {
        $all = $this->broadcasts();

        $this->assertNotEmpty($all, 'No broadcast was recorded.');

        return end($all);
    }

    // ---- the flag --------------------------------------------------------

    /**
     * The shipped default. Enabling this before NotificationsPizza carries
     * BroadcastSendHandler would make its consumer park every message.
     */
    public function test_nothing_is_broadcast_while_realtime_is_disabled(): void
    {
        config(['toolbox.tickets.realtime.enabled' => false]);

        $this->raise();

        $this->assertDatabaseCount('toolbox_outbox_events', 0);
    }

    // ---- the envelope ----------------------------------------------------

    public function test_raising_a_ticket_broadcasts_it(): void
    {
        $ticket = $this->raise();

        $data = $this->latest();

        $this->assertSame('ticket.created', $data['event']);
        $this->assertSame($ticket->id, $data['users'][0]['data']['ticket_id']);
        $this->assertSame('Screen is frozen', $data['users'][0]['data']['ticket']['title']);
    }

    /**
     * THE CAPABILITY-LEAK GUARD.
     *
     * One envelope reaches many people. Presenting the ticket for a particular
     * viewer would embed THAT person's capabilities and show everyone the
     * most-privileged recipient's buttons - a reader would be shown a Close
     * button the server would then refuse.
     */
    public function test_the_broadcast_ticket_carries_no_viewer_capabilities(): void
    {
        $this->raise();

        foreach ($this->broadcasts() as $data) {
            foreach ($data['users'] as $user) {
                $this->assertArrayNotHasKey('viewer', $user['data']['ticket']);
            }
        }
    }

    /**
     * The opposite of the notifier, and deliberately so: the person who acted
     * has other tabs and other devices, and they all need to converge.
     */
    public function test_the_actor_is_included_in_the_audience(): void
    {
        $this->raise();

        $ids = array_map(fn (array $u) => (int) $u['id'], $this->latest()['users']);
        sort($ids);

        $this->assertSame([$this->reporter->id, $this->assignee->id], $ids);
    }

    /**
     * Every recipient in one envelope gets the same payload, so the client can
     * apply it without asking who it was for.
     */
    public function test_every_recipient_receives_the_same_payload(): void
    {
        $this->raise();

        $payloads = array_map(fn (array $u) => $u['data'], $this->latest()['users']);

        $this->assertCount(2, $payloads);
        $this->assertSame($payloads[0], $payloads[1]);
    }

    public function test_a_reply_broadcasts_the_reply_alongside_the_ticket(): void
    {
        $ticket = $this->raise();

        $this->actAs($this->assignee);
        $this->postJson($this->url("/{$ticket->id}/responses"), ['body' => 'On my way'], $this->headers())
            ->assertCreated();

        $data = $this->latest();

        $this->assertSame('ticket.responded', $data['event']);
        $this->assertSame('On my way', $data['users'][0]['data']['response']['body']);
        $this->assertArrayNotHasKey('viewer', $data['users'][0]['data']['ticket']);
    }

    public function test_a_status_change_broadcasts_both_ends_of_the_transition(): void
    {
        $ticket = $this->raise();

        $this->actAs($this->assignee);
        $this->postJson($this->url("/{$ticket->id}/status"), ['status' => 'in_progress'], $this->headers())->assertOk();

        $data = $this->latest();

        $this->assertSame('ticket.status_changed', $data['event']);
        $this->assertSame('pending', $data['users'][0]['data']['from']);
        $this->assertSame('in_progress', $data['users'][0]['data']['to']);
        $this->assertFalse($data['users'][0]['data']['is_reopen']);
    }

    public function test_a_reopen_is_flagged_as_one(): void
    {
        $ticket = $this->raise();

        $this->actAs($this->assignee);
        $this->postJson($this->url("/{$ticket->id}/status"), ['status' => 'fixed'], $this->headers())->assertOk();
        $this->postJson($this->url("/{$ticket->id}/reopen"), ['reason' => 'Not fixed'], $this->headers())->assertOk();

        $data = $this->latest();

        $this->assertSame('fixed', $data['users'][0]['data']['from']);
        $this->assertSame('pending', $data['users'][0]['data']['to']);
        $this->assertTrue($data['users'][0]['data']['is_reopen']);
    }

    public function test_editing_a_ticket_broadcasts_the_new_state(): void
    {
        $ticket = $this->raise();

        $this->actAs($this->assignee);
        $this->postJson($this->url("/{$ticket->id}"), ['title' => 'Screen is black'], $this->headers())->assertOk();

        $data = $this->latest();

        $this->assertSame('ticket.updated', $data['event']);
        $this->assertSame('Screen is black', $data['users'][0]['data']['ticket']['title']);
    }

    /**
     * A newly added reader is part of the audience for the very event that added
     * them, so their open inbox picks the ticket up immediately.
     */
    public function test_adding_a_participant_broadcasts_to_them_too(): void
    {
        $ticket = $this->raise();
        $reader = $this->makeUser(43, 'Reader');

        $this->actAs($this->assignee);
        $this->postJson($this->url("/{$ticket->id}/participants"), [
            'user_id' => $reader->id, 'role' => 'reader',
        ], $this->headers())->assertCreated();

        $data = $this->latest();

        $this->assertSame('ticket.participants_changed', $data['event']);
        $this->assertContains($reader->id, array_map(fn (array $u) => (int) $u['id'], $data['users']));
    }

    /**
     * A ticket nobody is assigned to still has one watcher: the person who
     * raised it. Their own view has to converge, so the envelope is not empty.
     */
    public function test_the_reporter_is_always_in_their_own_audience(): void
    {
        $orphan = $this->makeUser(45, 'Orphan');
        $this->grantStore($orphan);
        $this->actAs($orphan);

        // The section has an assignee, so drop them out of the picture by
        // raising it against one nobody owns.
        TicketSection::query()->create(['key' => 'unowned.page', 'name' => 'Unowned']);

        $id = $this->postJson($this->url(), [
            'section_key' => 'unowned.page',
            'title' => 'Nobody owns this',
            'description' => 'Still broadcast to me.',
        ], $this->headers())->assertCreated()->json('data.id');

        $ids = array_map(fn (array $u) => (int) $u['id'], $this->latest()['users']);

        $this->assertSame([$orphan->id], $ids);
        $this->assertSame($id, $this->latest()['users'][0]['data']['ticket_id']);
    }
}
