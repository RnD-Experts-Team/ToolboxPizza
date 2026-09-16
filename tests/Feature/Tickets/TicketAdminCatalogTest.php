<?php

namespace Tests\Feature\Tickets;

use App\Models\TicketAssignment;
use App\Models\TicketLevel;
use App\Models\TicketSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

class TicketAdminCatalogTest extends TestCase
{
    use FakesAuthServer, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAuthServer();
    }

    // ---- sections ------------------------------------------------------

    public function test_a_section_is_created_with_the_key_the_dashboard_will_send(): void
    {
        $this->postJson('/api/v1/ticket-sections', [
            'key' => 'inventory.main-dashboard',
            'name' => 'Inventory - Main Dashboard',
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.key', 'inventory.main-dashboard')
            ->assertJsonPath('data.active', true);
    }

    public function test_a_section_key_must_look_like_an_identifier(): void
    {
        $this->postJson('/api/v1/ticket-sections', [
            'key' => 'Inventory Main Dashboard',
            'name' => 'Inventory',
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('key');
    }

    public function test_a_section_key_is_unique(): void
    {
        TicketSection::query()->create(['key' => 'hiring.page', 'name' => 'Hiring']);

        $this->postJson('/api/v1/ticket-sections', ['key' => 'hiring.page', 'name' => 'Hiring again'], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('key');
    }

    /**
     * The key is the dashboard's contract - renaming it would orphan every box
     * still sending the old one.
     */
    public function test_a_section_key_cannot_be_changed_by_an_update(): void
    {
        $section = TicketSection::query()->create(['key' => 'hiring.page', 'name' => 'Hiring']);

        $this->postJson("/api/v1/ticket-sections/{$section->id}", [
            'key' => 'something.else',
            'name' => 'Renamed',
        ], $this->headers())->assertOk();

        $this->assertDatabaseHas('ticket_sections', [
            'id' => $section->id,
            'key' => 'hiring.page',
            'name' => 'Renamed',
        ]);
    }

    public function test_deleting_a_section_retires_it_rather_than_removing_it(): void
    {
        $section = TicketSection::query()->create(['key' => 'hiring.page', 'name' => 'Hiring']);

        $this->deleteJson("/api/v1/ticket-sections/{$section->id}", [], $this->headers())->assertNoContent();

        // The row survives, because tickets point at it.
        $this->assertDatabaseHas('ticket_sections', ['id' => $section->id, 'active' => false]);
    }

    public function test_the_index_hides_retired_sections_unless_asked(): void
    {
        TicketSection::query()->create(['key' => 'live', 'name' => 'Live']);
        TicketSection::query()->create(['key' => 'retired', 'name' => 'Retired', 'active' => false]);

        $keys = array_column($this->getJson('/api/v1/ticket-sections', $this->headers())->json('data'), 'key');
        $this->assertSame(['live'], $keys);

        $all = array_column(
            $this->getJson('/api/v1/ticket-sections?include_inactive=1', $this->headers())->json('data'),
            'key',
        );
        $this->assertEqualsCanonicalizing(['live', 'retired'], $all);
    }

    // ---- levels --------------------------------------------------------

    public function test_levels_nest_and_the_index_returns_a_tree(): void
    {
        $top = TicketLevel::query()->create(['key' => 'operations', 'name' => 'Operations']);
        TicketLevel::query()->create(['key' => 'hiring', 'name' => 'Hiring', 'parent_id' => $top->id]);

        $tree = $this->getJson('/api/v1/ticket-levels', $this->headers())->assertOk()->json('data');

        $this->assertCount(1, $tree);
        $this->assertSame('operations', $tree[0]['key']);
        $this->assertSame('hiring', $tree[0]['children'][0]['key']);
    }

    public function test_a_level_cannot_be_parented_to_its_own_descendant(): void
    {
        $a = TicketLevel::query()->create(['key' => 'a', 'name' => 'A']);
        $b = TicketLevel::query()->create(['key' => 'b', 'name' => 'B', 'parent_id' => $a->id]);

        $this->postJson("/api/v1/ticket-levels/{$a->id}", ['parent_id' => $b->id], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TICKET_LEVEL_CYCLE');

        $this->assertDatabaseHas('ticket_levels', ['id' => $a->id, 'parent_id' => null]);
    }

    public function test_a_level_cannot_be_its_own_parent(): void
    {
        $a = TicketLevel::query()->create(['key' => 'a', 'name' => 'A']);

        $this->postJson("/api/v1/ticket-levels/{$a->id}", ['parent_id' => $a->id], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TICKET_LEVEL_CYCLE');
    }

    public function test_syncing_sections_replaces_the_whole_list(): void
    {
        $level = TicketLevel::query()->create(['key' => 'hiring', 'name' => 'Hiring']);
        $a = TicketSection::query()->create(['key' => 'a', 'name' => 'A']);
        $b = TicketSection::query()->create(['key' => 'b', 'name' => 'B']);

        $this->postJson("/api/v1/ticket-levels/{$level->id}/sections", [
            'section_ids' => [$a->id, $b->id],
        ], $this->headers())->assertOk();
        $this->assertSame(2, $level->sections()->count());

        $this->postJson("/api/v1/ticket-levels/{$level->id}/sections", [
            'section_ids' => [$a->id],
        ], $this->headers())->assertOk();
        $this->assertSame([$a->id], $level->sections()->pluck('ticket_sections.id')->all());
    }

    public function test_an_empty_sync_detaches_every_section(): void
    {
        $level = TicketLevel::query()->create(['key' => 'hiring', 'name' => 'Hiring']);
        $level->sections()->attach(TicketSection::query()->create(['key' => 'a', 'name' => 'A']));

        $this->postJson("/api/v1/ticket-levels/{$level->id}/sections", [
            'section_ids' => [],
        ], $this->headers())->assertOk();

        $this->assertSame(0, $level->sections()->count());
    }

    public function test_a_section_can_sit_under_several_levels_at_once(): void
    {
        $section = TicketSection::query()->create(['key' => 'hiring.page', 'name' => 'Hiring']);
        $hiring = TicketLevel::query()->create(['key' => 'hiring', 'name' => 'Hiring']);
        $compliance = TicketLevel::query()->create(['key' => 'compliance', 'name' => 'Compliance']);

        $this->postJson("/api/v1/ticket-levels/{$hiring->id}/sections", ['section_ids' => [$section->id]], $this->headers())->assertOk();
        $this->postJson("/api/v1/ticket-levels/{$compliance->id}/sections", ['section_ids' => [$section->id]], $this->headers())->assertOk();

        $this->assertSame(2, $section->levels()->count());
    }

    /**
     * Deactivating a mid-tree level severs the chain, so the response says how
     * many assignments it silences rather than letting that happen quietly.
     */
    public function test_retiring_a_level_reports_how_many_assignments_it_silences(): void
    {
        $top = TicketLevel::query()->create(['key' => 'operations', 'name' => 'Operations']);
        $mid = TicketLevel::query()->create(['key' => 'hiring', 'name' => 'Hiring', 'parent_id' => $top->id]);

        $u = User::query()->create(['id' => 10, 'name' => 'A', 'email' => 'a@example.com']);
        TicketAssignment::query()->create(['user_id' => $u->id, 'ticket_level_id' => $mid->id]);

        $this->deleteJson("/api/v1/ticket-levels/{$top->id}", [], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.assignments_affected', 1);
    }

    // ---- assignments ---------------------------------------------------

    public function test_an_assignment_needs_a_section_or_a_level(): void
    {
        User::query()->create(['id' => 10, 'name' => 'A', 'email' => 'a@example.com']);

        $this->postJson('/api/v1/ticket-assignments', ['user_id' => 10], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('ticket_section_id');
    }

    public function test_an_assignment_cannot_name_both_a_section_and_a_level(): void
    {
        User::query()->create(['id' => 10, 'name' => 'A', 'email' => 'a@example.com']);
        $section = TicketSection::query()->create(['key' => 'a', 'name' => 'A']);
        $level = TicketLevel::query()->create(['key' => 'l', 'name' => 'L']);

        $this->postJson('/api/v1/ticket-assignments', [
            'user_id' => 10,
            'ticket_section_id' => $section->id,
            'ticket_level_id' => $level->id,
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('ticket_section_id');
    }

    public function test_an_assignment_defaults_to_store_scoped(): void
    {
        User::query()->create(['id' => 10, 'name' => 'A', 'email' => 'a@example.com']);
        $section = TicketSection::query()->create(['key' => 'a', 'name' => 'A']);

        // Fail closed: an assignment made without thinking should reach too few
        // people, not every store in the estate.
        $this->postJson('/api/v1/ticket-assignments', [
            'user_id' => 10,
            'ticket_section_id' => $section->id,
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.store_scoped', true)
            ->assertJsonPath('data.via', 'section');
    }

    public function test_a_duplicate_assignment_is_refused(): void
    {
        User::query()->create(['id' => 10, 'name' => 'A', 'email' => 'a@example.com']);
        $section = TicketSection::query()->create(['key' => 'a', 'name' => 'A']);

        $payload = ['user_id' => 10, 'ticket_section_id' => $section->id];

        $this->postJson('/api/v1/ticket-assignments', $payload, $this->headers())->assertCreated();
        $this->postJson('/api/v1/ticket-assignments', $payload, $this->headers())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'TICKET_ASSIGNMENT_DUPLICATE');
    }

    public function test_the_same_user_may_hold_a_section_and_a_level_grant(): void
    {
        User::query()->create(['id' => 10, 'name' => 'A', 'email' => 'a@example.com']);
        $section = TicketSection::query()->create(['key' => 'a', 'name' => 'A']);
        $level = TicketLevel::query()->create(['key' => 'l', 'name' => 'L']);

        $this->postJson('/api/v1/ticket-assignments', ['user_id' => 10, 'ticket_section_id' => $section->id], $this->headers())->assertCreated();
        $this->postJson('/api/v1/ticket-assignments', ['user_id' => 10, 'ticket_level_id' => $level->id], $this->headers())->assertCreated();

        $this->assertSame(2, TicketAssignment::query()->where('user_id', 10)->count());
    }

    public function test_an_assignment_is_really_deleted(): void
    {
        User::query()->create(['id' => 10, 'name' => 'A', 'email' => 'a@example.com']);
        $section = TicketSection::query()->create(['key' => 'a', 'name' => 'A']);
        $a = TicketAssignment::query()->create(['user_id' => 10, 'ticket_section_id' => $section->id]);

        // Configuration, not history - unlike a section.
        $this->deleteJson("/api/v1/ticket-assignments/{$a->id}", [], $this->headers())->assertNoContent();

        $this->assertDatabaseCount('ticket_assignments', 0);
    }
}
