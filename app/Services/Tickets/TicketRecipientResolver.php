<?php

namespace App\Services\Tickets;

use App\Models\Store;
use App\Models\TicketAssignment;
use App\Models\TicketSection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who should receive a ticket for a (section, store).
 *
 * The heart of the feature. Everything downstream is plumbing.
 *
 * Two rules do the work:
 *
 *  1. ANCESTRY. A section's audience is whoever is assigned to that section,
 *     plus whoever is assigned to any level it sits under, plus every ancestor
 *     of those levels. Someone given "all of Hiring" gets a section three
 *     levels down without anyone maintaining a list.
 *
 *  2. THE STORE. An assignment never names stores. It carries `store_scoped`
 *     instead: true means the holder only receives tickets for stores they
 *     actually hold, checked against the replicated user_store_roles at
 *     resolution time - not trusted from the assignment.
 */
class TicketRecipientResolver
{
    public function __construct(
        private readonly TicketLevelGraph $graph,
        private readonly StoreAccessResolver $storeAccess,
    ) {}

    /**
     * Everyone the section reaches, BEFORE the store filter.
     *
     * `via` is surfaced by the /recipients endpoint so an admin looking at a
     * surprising recipient list can see why each person is on it.
     *
     * @return array<int, array{user_id: int, store_scoped: bool, via: string}>
     */
    public function candidatesFor(TicketSection $section): array
    {
        $levelIds = $this->levelIdsCovering($section);

        $rows = TicketAssignment::query()
            ->where('active', true)
            ->where(function (Builder $q) use ($section, $levelIds) {
                $q->where('ticket_section_id', $section->id);

                if ($levelIds !== []) {
                    $q->orWhereIn('ticket_level_id', $levelIds);
                }
            })
            ->get(['user_id', 'store_scoped', 'ticket_section_id', 'ticket_level_id']);

        // Collapse per user. One person can match through several rows - a
        // direct section grant AND an ancestor level grant, say.
        //
        // MOST-PERMISSIVE WINS: if ANY matching row is unscoped, the user is
        // unscoped. `store_scoped = false` is the broader grant, the same way
        // permissions_any is a union in pizzasys. The alternative - narrowest
        // wins - would mean adding a specific section assignment silently
        // revokes a broad level one, which no admin would predict.
        $collapsed = [];

        foreach ($rows as $row) {
            $userId = (int) $row->user_id;
            $scoped = (bool) $row->store_scoped;

            if (! isset($collapsed[$userId])) {
                $collapsed[$userId] = [
                    'user_id' => $userId,
                    'store_scoped' => $scoped,
                    'via' => $row->via(),
                ];

                continue;
            }

            if (! $scoped) {
                $collapsed[$userId]['store_scoped'] = false;
                $collapsed[$userId]['via'] = $row->via();
            }
        }

        return array_values($collapsed);
    }

    /**
     * The resolved recipient set for a ticket.
     *
     * @return array<int, int> distinct user ids, ascending
     */
    public function assigneesFor(TicketSection $section, Store $store): array
    {
        $unscoped = [];
        $scoped = [];

        foreach ($this->candidatesFor($section) as $candidate) {
            if ($candidate['store_scoped']) {
                $scoped[] = $candidate['user_id'];
            } else {
                $unscoped[] = $candidate['user_id'];
            }
        }

        $allowed = $this->storeAccess->filterUsersWithStoreAccess($scoped, $store->store_number);

        $ids = array_values(array_unique([...$unscoped, ...$allowed]));
        sort($ids);

        return $ids;
    }

    /**
     * Single-user check, for the show path where resolving the whole audience
     * would be wasteful.
     */
    public function isAssignee(int $userId, TicketSection $section, Store $store): bool
    {
        foreach ($this->candidatesFor($section) as $candidate) {
            if ($candidate['user_id'] !== $userId) {
                continue;
            }

            return $candidate['store_scoped'] === false
                || $this->storeAccess->canAccessStore($userId, $store->store_number);
        }

        return false;
    }

    /**
     * The reverse walk: every section this user is an assignee for, and whether
     * that grant is store-scoped.
     *
     * This is the DOWNWARD direction - from each assigned level, down through
     * its descendants to their sections. It feeds the inbox and the section
     * filter, so that listing tickets does not call isAssignee() per row.
     *
     * A test asserts this agrees with the upward walk for every (user, section)
     * pair; a disagreement is precisely the bug where someone can see a ticket
     * nobody ever notified them about.
     *
     * @return array<int, bool> section id => store_scoped
     */
    public function assignedSectionsFor(int $userId): array
    {
        $rows = TicketAssignment::query()
            ->where('active', true)
            ->where('user_id', $userId)
            ->get(['ticket_section_id', 'ticket_level_id', 'store_scoped']);

        $sections = [];

        $note = function (int $sectionId, bool $scoped) use (&$sections): void {
            // Same most-permissive collapse as the upward walk.
            $sections[$sectionId] = isset($sections[$sectionId])
                ? ($sections[$sectionId] && $scoped)
                : $scoped;
        };

        foreach ($rows as $row) {
            $scoped = (bool) $row->store_scoped;

            if ($row->ticket_section_id !== null) {
                $note((int) $row->ticket_section_id, $scoped);

                continue;
            }

            if ($row->ticket_level_id === null) {
                continue;
            }

            $levelIds = $this->graph->descendantsOf((int) $row->ticket_level_id);

            if ($levelIds === []) {
                continue;
            }

            $sectionIds = DB::table('ticket_level_section')
                ->whereIn('ticket_level_id', $levelIds)
                ->distinct()
                ->pluck('ticket_section_id');

            foreach ($sectionIds as $sectionId) {
                $note((int) $sectionId, $scoped);
            }
        }

        return $sections;
    }

    /**
     * Every level whose assignees should reach this section: the section's own
     * levels, plus every ancestor of each.
     *
     * Walking UP from the section is O(levels-of-section x depth). Walking down
     * from every level would be O(levels x sections) and is only used for the
     * reverse direction.
     *
     * @return array<int, int>
     */
    private function levelIdsCovering(TicketSection $section): array
    {
        $direct = $section->levels()
            ->where('ticket_levels.active', true)
            ->pluck('ticket_levels.id')
            ->all();

        $reachable = [];

        foreach ($direct as $levelId) {
            foreach ($this->graph->ancestorsOf((int) $levelId) as $ancestorId) {
                // Keyed to dedupe: two of the section's levels may share an
                // ancestor, and that person should appear once.
                $reachable[$ancestorId] = true;
            }
        }

        return array_keys($reachable);
    }
}
