<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketParticipantRole;
use App\Enums\TicketStatus;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketParticipant;
use App\Models\TicketSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTicketWorld;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * The cross-store inbox, and the filters over it.
 *
 * THIS ROUTE HAS NO STORE IN THE PATH, so pizzasys makes no per-store decision
 * on it - it is seeded store_scope_mode = 'none'. The visibility clause in
 * TicketQueryService is therefore the only thing standing between a caller and
 * another store's tickets, which is the entire reason user_store_roles is
 * replicated into this service.
 */
class TicketInboxFilterTest extends TestCase
{
    use BuildsTicketWorld, FakesAuthServer, RefreshDatabase;

    private const INBOX = '/api/v1/tickets';

    private User $viewer;

    private TicketSection $otherSection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildTicketWorld();

        $this->otherSection = TicketSection::query()->create(['key' => 'screens.menu', 'name' => 'Menu screens']);

        $this->viewer = $this->makeUser(40, 'Viewer');
        $this->fakeAuthServer($this->viewer);

        config([
            'toolbox.tickets.notifications.enabled' => false,
            'toolbox.tickets.realtime.enabled' => false,
        ]);
    }

    private function ticketAt(Store $store, ?TicketSection $section = null, ?int $reportedBy = null, ?TicketStatus $status = null): Ticket
    {
        return Ticket::factory()->create([
            'store_id' => $store->id,
            'ticket_section_id' => ($section ?? $this->section)->id,
            'reported_by' => $reportedBy ?? $this->makeUser(random_int(1000, 99999))->id,
            'status' => $status ?? TicketStatus::Pending,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, int>
     */
    private function inbox(array $query = []): array
    {
        $response = $this->getJson(self::INBOX.($query === [] ? '' : '?'.http_build_query($query)), $this->headers())
            ->assertOk();

        return array_map(fn (array $row) => (int) $row['id'], $response->json('data'));
    }

    // ---- what the inbox may show -----------------------------------------

    /**
     * The load-bearing one. Assigned to the section, holding only store 1 - the
     * inbox must not leak store 2 even though nothing upstream is adjudicating
     * this route.
     */
    public function test_a_store_scoped_assignee_never_sees_a_store_they_lack(): void
    {
        $this->assignSection($this->viewer);
        $this->grantStore($this->viewer);

        $mine = $this->ticketAt($this->store);
        $theirs = $this->ticketAt($this->otherStore);

        $ids = $this->inbox();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    /**
     * store_scoped = false is the broader grant: this person owns the section
     * everywhere, so the store filter is dropped rather than applied.
     */
    public function test_an_unscoped_assignee_sees_every_store(): void
    {
        $this->assignSection($this->viewer, storeScoped: false);

        $mine = $this->ticketAt($this->store);
        $theirs = $this->ticketAt($this->otherStore);

        $this->assertSame([$theirs->id, $mine->id], $this->inbox());
    }

    /**
     * The literal 'all' our own NATS handler writes, which is not a store code
     * and must not be compared as one.
     */
    public function test_an_all_stores_grant_reaches_every_store(): void
    {
        $this->assignSection($this->viewer);
        $this->grantStore($this->viewer, 'all');

        $mine = $this->ticketAt($this->store);
        $theirs = $this->ticketAt($this->otherStore);

        $ids = $this->inbox();

        $this->assertContains($mine->id, $ids);
        $this->assertContains($theirs->id, $ids);
    }

    /**
     * You always see what you reported, wherever it was raised and whatever has
     * happened to your store access since.
     */
    public function test_your_own_tickets_are_always_yours(): void
    {
        $ticket = $this->ticketAt($this->otherStore, reportedBy: $this->viewer->id);

        $this->assertSame([$ticket->id], $this->inbox());
    }

    /**
     * Being added by hand is a relationship to that ONE ticket, and it does not
     * depend on the store - that is what an explicit participant is for.
     */
    public function test_an_explicitly_added_reader_sees_that_ticket(): void
    {
        $ticket = $this->ticketAt($this->otherStore);

        TicketParticipant::query()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->viewer->id,
            'role' => TicketParticipantRole::Reader,
        ]);

        $this->assertSame([$ticket->id], $this->inbox());
    }

    public function test_someone_with_no_relationship_to_anything_sees_an_empty_inbox(): void
    {
        $this->grantStore($this->viewer);

        $this->ticketAt($this->store);
        $this->ticketAt($this->otherStore);

        $this->assertSame([], $this->inbox());
    }

    /**
     * An assignment through a LEVEL reaches the sections beneath it, so the
     * inbox has to resolve the graph downward as well.
     */
    public function test_a_level_assignment_reaches_the_sections_under_it(): void
    {
        $parent = $this->makeLevel('operations');
        $child = $this->makeLevel('front-of-house', $parent->id);
        $child->sections()->attach($this->section->id);

        $this->assignLevel($this->viewer, $parent, storeScoped: false);

        $reachable = $this->ticketAt($this->store);
        $unreachable = $this->ticketAt($this->store, $this->otherSection);

        $ids = $this->inbox();

        $this->assertContains($reachable->id, $ids);
        $this->assertNotContains($unreachable->id, $ids);
    }

    // ---- filters ----------------------------------------------------------

    public function test_statuses_filter_is_an_or_within_itself(): void
    {
        $this->assignSection($this->viewer, storeScoped: false);
        $this->assignSection($this->viewer, $this->otherSection, storeScoped: false);

        $pending = $this->ticketAt($this->store, status: TicketStatus::Pending);
        $fixed = $this->ticketAt($this->store, status: TicketStatus::Fixed);
        $closed = $this->ticketAt($this->store, status: TicketStatus::Closed);

        $ids = $this->inbox(['statuses' => ['pending', 'fixed']]);

        $this->assertContains($pending->id, $ids);
        $this->assertContains($fixed->id, $ids);
        $this->assertNotContains($closed->id, $ids);
    }

    /**
     * The house convention: an unparseable filter value is dropped silently
     * rather than 422'd, so a stale checkbox in the dashboard degrades to
     * "no filter" instead of breaking the page.
     */
    public function test_an_unknown_status_is_dropped_rather_than_refused(): void
    {
        $this->assignSection($this->viewer, storeScoped: false);

        $ticket = $this->ticketAt($this->store);

        $this->assertSame([$ticket->id], $this->inbox(['statuses' => ['not-a-status']]));
    }

    public function test_the_section_filter_speaks_keys_and_ids(): void
    {
        $this->assignSection($this->viewer, storeScoped: false);
        $this->assignSection($this->viewer, $this->otherSection, storeScoped: false);

        $hiring = $this->ticketAt($this->store);
        $screens = $this->ticketAt($this->store, $this->otherSection);

        $this->assertSame([$hiring->id], $this->inbox(['section_keys' => [$this->section->key]]));
        $this->assertSame([$screens->id], $this->inbox(['section_ids' => [$this->otherSection->id]]));
    }

    /**
     * The store checkboxes send CODES, matching what the path speaks in.
     */
    public function test_the_store_filter_takes_store_numbers(): void
    {
        $this->assignSection($this->viewer, storeScoped: false);

        $here = $this->ticketAt($this->store);
        $there = $this->ticketAt($this->otherStore);

        $this->assertSame([$here->id], $this->inbox(['stores' => [$this->store->store_number]]));
        $this->assertSame([$there->id], $this->inbox(['stores' => [$this->otherStore->store_number]]));
    }

    /**
     * A filter cannot widen visibility - it narrows what the caller may already
     * see, and asking for a store they lack returns nothing rather than leaking.
     */
    public function test_filtering_by_a_store_you_lack_returns_nothing(): void
    {
        $this->assignSection($this->viewer);
        $this->grantStore($this->viewer);

        $this->ticketAt($this->otherStore);

        $this->assertSame([], $this->inbox(['stores' => [$this->otherStore->store_number]]));
    }

    public function test_search_matches_the_title_or_the_description(): void
    {
        $this->assignSection($this->viewer, storeScoped: false);

        $byTitle = Ticket::factory()->create([
            'store_id' => $this->store->id,
            'ticket_section_id' => $this->section->id,
            'reported_by' => $this->viewer->id,
            'title' => 'Fryer is leaking',
            'description' => 'Nothing to see',
        ]);

        $byDescription = Ticket::factory()->create([
            'store_id' => $this->store->id,
            'ticket_section_id' => $this->section->id,
            'reported_by' => $this->viewer->id,
            'title' => 'Nothing to see',
            'description' => 'The fryer again',
        ]);

        $ids = $this->inbox(['search' => 'fryer']);

        $this->assertContains($byTitle->id, $ids);
        $this->assertContains($byDescription->id, $ids);
    }

    public function test_reported_by_narrows_to_one_persons_tickets(): void
    {
        $this->assignSection($this->viewer, storeScoped: false);

        $someone = $this->makeUser(77, 'Someone');
        $theirs = $this->ticketAt($this->store, reportedBy: $someone->id);
        $this->ticketAt($this->store);

        $this->assertSame([$theirs->id], $this->inbox(['reported_by' => $someone->id]));
    }

    // ---- paging and ordering ---------------------------------------------

    /**
     * Clamped, so ?per_page=100000 cannot be used to pull the whole table in one
     * request - one of the two things BreakQueryService got right that the older
     * siblings did not.
     */
    public function test_the_page_size_is_clamped_at_both_ends(): void
    {
        $this->assignSection($this->viewer, storeScoped: false);

        foreach (range(1, 3) as $i) {
            $this->ticketAt($this->store);
        }

        $this->assertSame(200, $this->getJson(self::INBOX.'?per_page=100000', $this->headers())->json('per_page'));
        $this->assertSame(1, $this->getJson(self::INBOX.'?per_page=0', $this->headers())->json('per_page'));
    }

    /**
     * Most recently touched first, with the id as a tiebreaker so two tickets
     * sharing a last_activity_at keep a stable order across pages - the other
     * thing the older siblings get wrong.
     */
    public function test_the_order_is_recent_activity_then_id(): void
    {
        $this->assignSection($this->viewer, storeScoped: false);

        $shared = now()->subHour();

        $first = $this->ticketAt($this->store);
        $second = $this->ticketAt($this->store);
        $newest = $this->ticketAt($this->store);

        $first->forceFill(['last_activity_at' => $shared])->save();
        $second->forceFill(['last_activity_at' => $shared])->save();
        $newest->forceFill(['last_activity_at' => now()])->save();

        $this->assertSame([$newest->id, $second->id, $first->id], $this->inbox());
    }
}
