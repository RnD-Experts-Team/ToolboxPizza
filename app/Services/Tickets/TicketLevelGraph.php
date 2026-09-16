<?php

namespace App\Services\Tickets;

use App\Models\TicketLevel;

/**
 * Walks the level hierarchy.
 *
 * Levels nest and a section may sit under several at once, so this is a
 * directed graph rather than a tree. Two directions are needed and they are not
 * interchangeable:
 *
 *   ancestorsOf()   - UP. Resolving who receives a ticket: from the section's
 *                     levels, up through every ancestor, because an assignee
 *                     high in the tree must reach a section buried beneath them.
 *
 *   descendantsOf() - DOWN. Resolving what a user can see: from each assigned
 *                     level, down to every section beneath it.
 *
 * The whole active catalog is loaded once and walked in memory. This is an admin
 * catalog of tens of rows, not a job for a recursive CTE, and doing it in PHP is
 * what gives the cycle guard somewhere to live.
 */
class TicketLevelGraph
{
    /**
     * A cycle that gets past the write-time guard must not hang a worker. This
     * bounds the walk even if the visited set were somehow defeated.
     */
    private const MAX_DEPTH = 32;

    /** @var array<int, int|null>|null active level id => parent id (null = root) */
    private ?array $parents = null;

    /** @var array<int, array<int, int>>|null parent id => child ids */
    private ?array $children = null;

    /**
     * Drop the memoised graph. Called after any level write, because a stale
     * parent map would resolve the wrong audience for the rest of the request.
     */
    public function refresh(): void
    {
        $this->parents = null;
        $this->children = null;
    }

    /**
     * The level itself plus every ancestor, nearest first.
     *
     * @return array<int, int>
     */
    public function ancestorsOf(int $levelId): array
    {
        $parents = $this->parents();

        // An inactive level is absent from the map entirely, which SEVERS the
        // chain rather than walking through it. Deactivating a mid-tree level
        // therefore stops tickets reaching everyone above it - deliberate, and
        // the alternative would make `active` mean nothing mid-tree.
        if (! array_key_exists($levelId, $parents)) {
            return [];
        }

        $chain = [];
        $visited = [];
        $current = $levelId;
        $depth = 0;

        while ($current !== null && $depth < self::MAX_DEPTH) {
            // The seatbelt. A cycle introduced outside the API - a seeder, a
            // manual UPDATE, a restored backup - truncates the chain here
            // instead of looping forever.
            if (isset($visited[$current])) {
                break;
            }

            $visited[$current] = true;
            $chain[] = $current;

            $current = $parents[$current] ?? null;
            $depth++;
        }

        return $chain;
    }

    /**
     * The level itself plus every descendant.
     *
     * @return array<int, int>
     */
    public function descendantsOf(int $levelId): array
    {
        $children = $this->children();

        if (! array_key_exists($levelId, $this->parents())) {
            return [];
        }

        $found = [];
        $queue = [$levelId];
        $depth = 0;

        while ($queue !== [] && $depth < self::MAX_DEPTH) {
            $next = [];

            foreach ($queue as $id) {
                if (isset($found[$id])) {
                    continue;
                }

                $found[$id] = true;

                foreach ($children[$id] ?? [] as $childId) {
                    if (! isset($found[$childId])) {
                        $next[] = $childId;
                    }
                }
            }

            $queue = $next;
            $depth++;
        }

        return array_keys($found);
    }

    /**
     * Would making $candidateParentId the parent of $levelId create a cycle?
     *
     * This is the PREVENTION half, called by TicketLevelService before any
     * parent is written - the only thing standing between an admin and a graph
     * that eats itself. The visited sets above are the containment half.
     */
    public function wouldCycle(int $levelId, ?int $candidateParentId): bool
    {
        if ($candidateParentId === null) {
            return false;
        }

        // Its own parent.
        if ($candidateParentId === $levelId) {
            return true;
        }

        // Anything already beneath it cannot also be above it.
        return in_array($candidateParentId, $this->descendantsOf($levelId), true);
    }

    /**
     * @return array<int, int|null>
     */
    private function parents(): array
    {
        if ($this->parents === null) {
            $this->load();
        }

        return $this->parents ?? [];
    }

    /**
     * @return array<int, array<int, int>>
     */
    private function children(): array
    {
        if ($this->children === null) {
            $this->load();
        }

        return $this->children ?? [];
    }

    /**
     * One query for the whole graph.
     */
    private function load(): void
    {
        $rows = TicketLevel::query()
            ->where('active', true)
            ->get(['id', 'parent_id']);

        $parents = [];
        $children = [];

        foreach ($rows as $row) {
            $parents[(int) $row->id] = $row->parent_id === null ? null : (int) $row->parent_id;
        }

        // A parent that is itself inactive is treated as absent, so the chain
        // stops there rather than silently jumping the gap.
        foreach ($parents as $id => $parentId) {
            if ($parentId !== null && ! array_key_exists($parentId, $parents)) {
                $parents[$id] = null;

                continue;
            }

            if ($parentId !== null) {
                $children[$parentId][] = $id;
            }
        }

        $this->parents = $parents;
        $this->children = $children;
    }
}
