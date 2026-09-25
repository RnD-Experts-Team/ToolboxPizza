<?php

namespace Tests\Feature\Tickets;

use App\Models\TicketLevel;
use App\Services\Tickets\TicketAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TicketLevelGraphTest extends TestCase
{
    use RefreshDatabase;

    private function level(string $key, ?int $parentId = null, bool $active = true): TicketLevel
    {
        return TicketLevel::query()->create([
            'key' => $key,
            'name' => ucfirst($key),
            'parent_id' => $parentId,
            'active' => $active,
        ]);
    }

    private function graph(): TicketAccessService
    {
        return app(TicketAccessService::class);
    }

    public function test_ancestry_walks_all_the_way_to_the_root(): void
    {
        $l1 = $this->level('l1');
        $l2 = $this->level('l2', $l1->id);
        $l3 = $this->level('l3', $l2->id);
        $l4 = $this->level('l4', $l3->id);

        // Nearest first, so a caller can reason about distance if it ever wants to.
        $this->assertSame([$l4->id, $l3->id, $l2->id, $l1->id], $this->graph()->ancestorsOf($l4->id));
    }

    public function test_descendants_reach_every_branch(): void
    {
        $root = $this->level('root');
        $a = $this->level('a', $root->id);
        $b = $this->level('b', $root->id);
        $deep = $this->level('deep', $a->id);

        $this->assertEqualsCanonicalizing(
            [$root->id, $a->id, $b->id, $deep->id],
            $this->graph()->descendantsOf($root->id),
        );

        // Direction matters: a child does not contain its parent.
        $this->assertEqualsCanonicalizing([$a->id, $deep->id], $this->graph()->descendantsOf($a->id));
    }

    /**
     * An inactive level is absent from the graph, so it SEVERS the chain rather
     * than being walked through. Deactivating a mid-tree level stops tickets
     * reaching everyone above it.
     */
    public function test_an_inactive_level_severs_the_chain(): void
    {
        $l1 = $this->level('l1');
        $l2 = $this->level('l2', $l1->id, active: false);
        $l3 = $this->level('l3', $l2->id);

        $this->assertSame([$l3->id], $this->graph()->ancestorsOf($l3->id));
    }

    public function test_an_inactive_level_has_no_ancestry_of_its_own(): void
    {
        $l1 = $this->level('l1');
        $l2 = $this->level('l2', $l1->id, active: false);

        $this->assertSame([], $this->graph()->ancestorsOf($l2->id));
    }

    /**
     * THE CONTAINMENT GUARD.
     *
     * The service refuses to write a cycle, but a seeder, a manual UPDATE or a
     * restored backup can still produce one. The walk must truncate, not hang a
     * worker forever.
     */
    public function test_a_cycle_written_straight_into_the_table_terminates(): void
    {
        $a = $this->level('a');
        $b = $this->level('b', $a->id);
        $c = $this->level('c', $b->id);

        // Straight past the service and its guard.
        DB::table('ticket_levels')->where('id', $a->id)->update(['parent_id' => $c->id]);

        $chain = $this->graph()->ancestorsOf($a->id);

        // Every node appears exactly once, and it stops.
        $this->assertSame(count($chain), count(array_unique($chain)));
        $this->assertLessThanOrEqual(3, count($chain));
        $this->assertContains($a->id, $chain);
    }

    public function test_a_cycle_does_not_hang_the_descendant_walk_either(): void
    {
        $a = $this->level('a');
        $b = $this->level('b', $a->id);

        DB::table('ticket_levels')->where('id', $a->id)->update(['parent_id' => $b->id]);

        $found = $this->graph()->descendantsOf($a->id);

        $this->assertSame(count($found), count(array_unique($found)));
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $found);
    }

    // ---- wouldCycle: the prevention half -------------------------------

    public function test_a_level_cannot_be_its_own_parent(): void
    {
        $a = $this->level('a');

        $this->assertTrue($this->graph()->wouldCycle($a->id, $a->id));
    }

    public function test_a_level_cannot_be_parented_to_its_own_descendant(): void
    {
        $a = $this->level('a');
        $b = $this->level('b', $a->id);
        $c = $this->level('c', $b->id);

        // Putting A under its grandchild C closes the loop.
        $this->assertTrue($this->graph()->wouldCycle($a->id, $c->id));
    }

    public function test_a_sibling_is_a_legal_parent(): void
    {
        $root = $this->level('root');
        $a = $this->level('a', $root->id);
        $b = $this->level('b', $root->id);

        $this->assertFalse($this->graph()->wouldCycle($a->id, $b->id));
    }

    public function test_detaching_to_a_root_is_always_legal(): void
    {
        $a = $this->level('a');
        $b = $this->level('b', $a->id);

        $this->assertFalse($this->graph()->wouldCycle($b->id, null));
    }

    /**
     * The graph memoises its parent map for the request, so a write has to
     * invalidate it or the rest of the request resolves against a stale tree.
     */
    public function test_refresh_picks_up_a_new_level(): void
    {
        $a = $this->level('a');
        $graph = $this->graph();

        $this->assertSame([$a->id], $graph->ancestorsOf($a->id));

        $b = $this->level('b', $a->id);
        $graph->refresh();

        $this->assertSame([$b->id, $a->id], $graph->ancestorsOf($b->id));
    }

    public function test_an_unknown_level_resolves_to_nothing(): void
    {
        $this->assertSame([], $this->graph()->ancestorsOf(9999));
        $this->assertSame([], $this->graph()->descendantsOf(9999));
    }
}
