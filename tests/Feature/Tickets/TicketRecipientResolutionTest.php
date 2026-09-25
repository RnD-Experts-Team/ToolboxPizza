<?php

namespace Tests\Feature\Tickets;

use App\Models\Store;
use App\Models\TicketAssignment;
use App\Models\TicketLevel;
use App\Models\TicketSection;
use App\Models\User;
use App\Models\UserStoreRole;
use App\Services\Tickets\TicketAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who receives a ticket. The heaviest file in the module, because everything
 * else assumes this is right - and a mistake here is silent: the wrong people
 * simply never hear about a ticket.
 */
class TicketRecipientResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Store $otherStore;

    private TicketSection $section;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::query()->create(['id' => 1, 'store_number' => '03795-00001', 'name' => 'Downtown']);
        $this->otherStore = Store::query()->create(['id' => 2, 'store_number' => '03795-00002', 'name' => 'Uptown']);

        $this->section = TicketSection::query()->create(['key' => 'hiring.page', 'name' => 'Hiring page']);
    }

    private function user(int $id): User
    {
        return User::query()->create(['id' => $id, 'name' => "User {$id}", 'email' => "u{$id}@example.com"]);
    }

    private function level(string $key, ?int $parentId = null, bool $active = true): TicketLevel
    {
        return TicketLevel::query()->create([
            'key' => $key, 'name' => ucfirst($key), 'parent_id' => $parentId, 'active' => $active,
        ]);
    }

    private function assignToSection(User $u, TicketSection $s, bool $storeScoped = true, bool $active = true): void
    {
        TicketAssignment::query()->create([
            'user_id' => $u->id, 'ticket_section_id' => $s->id,
            'store_scoped' => $storeScoped, 'active' => $active,
        ]);
    }

    private function assignToLevel(User $u, TicketLevel $l, bool $storeScoped = true, bool $active = true): void
    {
        TicketAssignment::query()->create([
            'user_id' => $u->id, 'ticket_level_id' => $l->id,
            'store_scoped' => $storeScoped, 'active' => $active,
        ]);
    }

    private function grantStore(User $u, string $storeCode, bool $active = true): void
    {
        UserStoreRole::query()->create([
            'id' => random_int(100000, 999999),
            'user_id' => $u->id, 'store_id' => $storeCode,
            'role_name' => 'hiring_manager', 'active' => $active,
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function recipients(?Store $store = null): array
    {
        return app(TicketAccessService::class)->assigneesFor($this->section, $store ?? $this->store);
    }

    // ---- ancestry ------------------------------------------------------

    public function test_a_direct_section_assignee_receives(): void
    {
        $u = $this->user(10);
        $this->assignToSection($u, $this->section);
        $this->grantStore($u, '03795-00001');

        $this->assertSame([10], $this->recipients());
    }

    public function test_an_assignee_of_the_sections_own_level_receives(): void
    {
        $level = $this->level('hiring');
        $this->section->levels()->attach($level);

        $u = $this->user(10);
        $this->assignToLevel($u, $level);
        $this->grantStore($u, '03795-00001');

        $this->assertSame([10], $this->recipients());
    }

    /**
     * Ancestry, not just the immediate parent - "someone who gets the high level
     * ones gets the things under his level".
     */
    public function test_an_assignee_of_a_grandparent_level_receives(): void
    {
        $top = $this->level('operations');
        $mid = $this->level('hiring', $top->id);
        $leaf = $this->level('hiring-requests', $mid->id);
        $this->section->levels()->attach($leaf);

        $u = $this->user(10);
        $this->assignToLevel($u, $top);
        $this->grantStore($u, '03795-00001');

        $this->assertSame([10], $this->recipients());
    }

    public function test_a_section_under_two_levels_reaches_assignees_of_both(): void
    {
        $hiring = $this->level('hiring');
        $compliance = $this->level('compliance');
        $this->section->levels()->attach([$hiring->id, $compliance->id]);

        $a = $this->user(10);
        $b = $this->user(11);
        $this->assignToLevel($a, $hiring);
        $this->assignToLevel($b, $compliance);
        $this->grantStore($a, '03795-00001');
        $this->grantStore($b, '03795-00001');

        $this->assertSame([10, 11], $this->recipients());
    }

    public function test_a_user_reached_by_two_paths_appears_once(): void
    {
        $top = $this->level('operations');
        $a = $this->level('a', $top->id);
        $b = $this->level('b', $top->id);
        $this->section->levels()->attach([$a->id, $b->id]);

        $u = $this->user(10);
        $this->assignToLevel($u, $top); // reachable via both branches
        $this->grantStore($u, '03795-00001');

        $this->assertSame([10], $this->recipients());
    }

    /**
     * Direction matters. A level BELOW the section's level is not an ancestor,
     * and its assignees are not responsible for the section.
     */
    public function test_an_assignee_of_a_level_below_the_section_does_not_receive(): void
    {
        $mid = $this->level('hiring');
        $below = $this->level('hiring-detail', $mid->id);
        $this->section->levels()->attach($mid);

        $u = $this->user(10);
        $this->assignToLevel($u, $below);
        $this->grantStore($u, '03795-00001');

        $this->assertSame([], $this->recipients());
    }

    public function test_an_inactive_assignment_is_ignored(): void
    {
        $u = $this->user(10);
        $this->assignToSection($u, $this->section, active: false);
        $this->grantStore($u, '03795-00001');

        $this->assertSame([], $this->recipients());
    }

    // ---- the store filter ----------------------------------------------

    public function test_a_store_scoped_assignee_without_the_store_is_excluded(): void
    {
        $u = $this->user(10);
        $this->assignToSection($u, $this->section, storeScoped: true);
        $this->grantStore($u, '03795-00002'); // a different store

        $this->assertSame([], $this->recipients());
        // ...and would receive for the store they DO hold.
        $this->assertSame([10], $this->recipients($this->otherStore));
    }

    public function test_an_all_stores_grant_counts_for_every_store(): void
    {
        $u = $this->user(10);
        $this->assignToSection($u, $this->section, storeScoped: true);
        $this->grantStore($u, 'all');

        $this->assertSame([10], $this->recipients());
        $this->assertSame([10], $this->recipients($this->otherStore));
    }

    public function test_a_null_store_grant_is_treated_as_all_stores(): void
    {
        // The estate's older shape for an unscoped grant; still live in
        // replicated rows.
        $u = $this->user(10);
        $this->assignToSection($u, $this->section, storeScoped: true);
        UserStoreRole::query()->create([
            'id' => 1, 'user_id' => 10, 'store_id' => null, 'role_name' => 'r', 'active' => true,
        ]);

        $this->assertSame([10], $this->recipients());
    }

    public function test_an_inactive_store_grant_does_not_count(): void
    {
        $u = $this->user(10);
        $this->assignToSection($u, $this->section, storeScoped: true);
        $this->grantStore($u, '03795-00001', active: false);

        $this->assertSame([], $this->recipients());
    }

    public function test_an_unscoped_assignee_receives_without_any_store_grant(): void
    {
        $u = $this->user(10);
        $this->assignToSection($u, $this->section, storeScoped: false);
        // No user_store_roles row at all.

        $this->assertSame([10], $this->recipients());
        $this->assertSame([10], $this->recipients($this->otherStore));
    }

    /**
     * THE ID-VS-CODE TRAP.
     *
     * user_store_roles.store_id holds the CODE. A row holding the numeric
     * primary key as a string must NOT be read as access to that store.
     */
    public function test_a_grant_holding_the_numeric_pk_does_not_match_the_store(): void
    {
        $store = Store::query()->create(['id' => 7, 'store_number' => '00007-00001', 'name' => 'Seven']);

        $u = $this->user(10);
        $this->assignToSection($u, $this->section, storeScoped: true);
        UserStoreRole::query()->create([
            'id' => 1, 'user_id' => 10, 'store_id' => '7', 'role_name' => 'r', 'active' => true,
        ]);

        $this->assertSame([], $this->recipients($store));
    }

    // ---- the collapse rule ---------------------------------------------

    /**
     * Most-permissive wins: a narrow section grant must not silently revoke a
     * broad level one.
     */
    public function test_an_unscoped_level_grant_beats_a_scoped_section_grant(): void
    {
        $level = $this->level('hiring');
        $this->section->levels()->attach($level);

        $u = $this->user(10);
        $this->assignToSection($u, $this->section, storeScoped: true);
        $this->assignToLevel($u, $level, storeScoped: false);
        // No store grant at all - the unscoped level assignment carries them.

        $this->assertSame([10], $this->recipients());
    }

    public function test_candidates_report_how_each_person_was_reached(): void
    {
        $level = $this->level('hiring');
        $this->section->levels()->attach($level);

        $direct = $this->user(10);
        $viaLevel = $this->user(11);
        $this->assignToSection($direct, $this->section);
        $this->assignToLevel($viaLevel, $level);

        $candidates = collect(app(TicketAccessService::class)->candidatesFor($this->section))
            ->keyBy('user_id');

        $this->assertSame('section', $candidates[10]['via']);
        $this->assertSame('level:'.$level->id, $candidates[11]['via']);
    }

    // ---- isAssignee agrees with the set --------------------------------

    public function test_is_assignee_agrees_with_the_resolved_set(): void
    {
        $level = $this->level('hiring');
        $this->section->levels()->attach($level);

        $scopedWith = $this->user(10);
        $scopedWithout = $this->user(11);
        $unscoped = $this->user(12);
        $unrelated = $this->user(13);

        $this->assignToLevel($scopedWith, $level);
        $this->assignToLevel($scopedWithout, $level);
        $this->assignToSection($unscoped, $this->section, storeScoped: false);

        $this->grantStore($scopedWith, '03795-00001');
        $this->grantStore($scopedWithout, '03795-00002');

        $resolver = app(TicketAccessService::class);
        $set = $this->recipients();

        foreach ([$scopedWith, $scopedWithout, $unscoped, $unrelated] as $u) {
            $this->assertSame(
                in_array($u->id, $set, true),
                $resolver->isAssignee($u->id, $this->section, $this->store),
                "isAssignee disagreed with the resolved set for user {$u->id}",
            );
        }
    }

    /**
     * The two walks must agree, or someone sees a ticket nobody told them about.
     */
    public function test_the_downward_walk_agrees_with_the_upward_one(): void
    {
        $top = $this->level('operations');
        $mid = $this->level('hiring', $top->id);
        $this->section->levels()->attach($mid);

        $other = TicketSection::query()->create(['key' => 'other', 'name' => 'Other']);
        $other->levels()->attach($top);

        $u = $this->user(10);
        $this->assignToLevel($u, $top, storeScoped: false);

        $resolver = app(TicketAccessService::class);
        $sections = $resolver->assignedSectionsFor($u->id);

        // Reached both sections by walking down...
        $this->assertArrayHasKey($this->section->id, $sections);
        $this->assertArrayHasKey($other->id, $sections);

        // ...and the upward walk says the same for each.
        $this->assertTrue($resolver->isAssignee($u->id, $this->section, $this->store));
        $this->assertTrue($resolver->isAssignee($u->id, $other, $this->store));
    }

    public function test_a_section_with_no_assignments_resolves_to_nobody(): void
    {
        // Not an error - the ticket is still created, with a warning.
        $this->assertSame([], $this->recipients());
    }
}
