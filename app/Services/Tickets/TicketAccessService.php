<?php

namespace App\Services\Tickets;

use App\Enums\TicketParticipantRole;
use App\Enums\TicketStatus;
use App\Enums\TicketViewerRole;
use App\Exceptions\TicketException;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketAssignment;
use App\Models\TicketLevel;
use App\Models\TicketParticipant;
use App\Models\TicketSection;
use App\Models\User;
use App\Services\StoreAccessResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Who a ticket reaches, and what each person may do to it. THE single source of
 * truth for both - controllers ask, they never decide.
 *
 * Routing, the heart of the feature, is two rules:
 *
 *  1. ANCESTRY. A section's audience is whoever is assigned to that section,
 *     plus whoever is assigned to any level it sits under, plus every ancestor
 *     of those levels. Someone given "all of Hiring" gets a section three levels
 *     down without anyone maintaining a list.
 *
 *  2. THE STORE. An assignment never names stores. It carries `store_scoped`
 *     instead: true means the holder only receives tickets for stores they
 *     actually hold, checked against the replicated user_store_roles at
 *     resolution time - not trusted from the assignment.
 */
class TicketAccessService
{
    /**
     * A cycle that gets past the write-time guard must not hang a worker. This
     * bounds every level walk even if the visited set were somehow defeated.
     */
    private const MAX_DEPTH = 32;

    /** @var array<int, int|null>|null active level id => parent id, memoised per request */
    private ?array $parents = null;

    /** @var array<int, array<int, int>>|null parent id => child ids */
    private ?array $children = null;

    public function __construct(private readonly StoreAccessResolver $storeAccess) {}

    // -------------------------------------------------------------------------
    // Resolving a ticket for a caller
    // -------------------------------------------------------------------------

    /**
     * A ticket the caller may see, or 404. Two filters, in order:
     *
     *  1. Scoped to the store in the path, so a ticket id from another store is
     *     a 404 here even when the caller legitimately holds that other store.
     *  2. The permission matrix, which 404s rather than 403s when the caller has
     *     no relationship to the ticket - a 403 would confirm the id exists.
     */
    public function findVisible(User $user, Store $store, int $ticketId): Ticket
    {
        $ticket = Ticket::query()
            ->where('store_id', $store->id)
            ->with(['store', 'section', 'reporter', 'participants.user'])
            ->findOrFail($ticketId);

        $this->assertCanView($user, $ticket);

        return $ticket;
    }

    // -------------------------------------------------------------------------
    // The permission matrix
    // -------------------------------------------------------------------------

    /**
     * What this user is to this ticket. Two sources of authority are combined
     * and the HIGHEST wins - so a reporter who also happens to be an assignee
     * keeps the assignee's powers. Never stored: assignment is dynamic.
     */
    public function roleFor(User $user, Ticket $ticket): TicketViewerRole
    {
        $participant = $ticket->participants->first(fn (TicketParticipant $p) => (int) $p->user_id === (int) $user->id)?->role;

        // An explicit assignee outranks everything - the deliberate override
        // for "this one needs Dana".
        if ($participant === TicketParticipantRole::Assignee) {
            return TicketViewerRole::ExceptionAssignee;
        }

        if ($ticket->section !== null && $ticket->store !== null && $this->isAssignee($user->id, $ticket->section, $ticket->store)) {
            return TicketViewerRole::Assignee;
        }

        if ((int) $ticket->reported_by === (int) $user->id) {
            return TicketViewerRole::Reporter;
        }

        return match ($participant) {
            TicketParticipantRole::Responder => TicketViewerRole::Responder,
            TicketParticipantRole::Reader => TicketViewerRole::Reader,
            default => TicketViewerRole::None,
        };
    }

    /**
     * Editing the title or description. An assignee may always. The reporter
     * may only while the ticket is still Pending: once somebody has picked it
     * up, silently rewriting the description invalidates what they acted on.
     */
    public function canEdit(User $user, Ticket $ticket): bool
    {
        $role = $this->roleFor($user, $ticket);

        return $role->canChangeStatus()
            || ($role === TicketViewerRole::Reporter && $ticket->status === TicketStatus::Pending);
    }

    /**
     * View is the ONLY ability that 404s. A 403 would confirm the ticket exists,
     * which is enough to probe for them.
     *
     * @throws ModelNotFoundException
     */
    public function assertCanView(User $user, Ticket $ticket): void
    {
        if (! $this->roleFor($user, $ticket)->canView()) {
            throw (new ModelNotFoundException)->setModel(Ticket::class, [$ticket->id]);
        }
    }

    /**
     * Every other ability 403s, because the caller can already see the thing.
     *
     * @throws TicketException
     */
    public function assertCan(string $ability, User $user, Ticket $ticket): void
    {
        $role = $this->roleFor($user, $ticket);

        $allowed = match ($ability) {
            'respond' => $role->canRespond(),
            'change status' => $role->canChangeStatus(),
            'manage participants of' => $role->canManageParticipants(),
            'edit' => $this->canEdit($user, $ticket),
            default => false,
        };

        if (! $allowed) {
            throw TicketException::forbidden($ability, $role);
        }
    }

    /**
     * What the dashboard should render - the same answers the server would give,
     * so the buttons on screen are exactly the ones that will work.
     *
     * @return array<string, mixed>
     */
    public function capabilities(User $user, Ticket $ticket): array
    {
        $role = $this->roleFor($user, $ticket);

        return [
            'role' => $role->value,
            'role_label' => $role->label(),
            'can' => [
                'view' => $role->canView(),
                'respond' => $role->canRespond(),
                'change_status' => $role->canChangeStatus(),
                'manage_participants' => $role->canManageParticipants(),
                'edit' => $this->canEdit($user, $ticket),
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Routing: who a (section, store) reaches
    // -------------------------------------------------------------------------

    /**
     * Everyone the section reaches, BEFORE the store filter. `via` is surfaced
     * by the /recipients endpoint so an admin can see why each person is on it.
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

        // Collapse per user - one person can match through several rows.
        //
        // MOST-PERMISSIVE WINS: if ANY matching row is unscoped, the user is
        // unscoped. The alternative - narrowest wins - would mean adding a
        // specific section assignment silently revokes a broad level one, which
        // no admin would predict.
        $collapsed = [];

        foreach ($rows as $row) {
            $userId = (int) $row->user_id;
            $scoped = (bool) $row->store_scoped;

            if (! isset($collapsed[$userId])) {
                $collapsed[$userId] = ['user_id' => $userId, 'store_scoped' => $scoped, 'via' => $row->via()];
            } elseif (! $scoped) {
                $collapsed[$userId]['store_scoped'] = false;
                $collapsed[$userId]['via'] = $row->via();
            }
        }

        return array_values($collapsed);
    }

    /**
     * Who actually receives a ticket for this (section, store).
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

        $ids = array_values(array_unique([
            ...$unscoped,
            ...$this->storeAccess->filterUsersWithStoreAccess($scoped, $store->store_number),
        ]));
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
            if ($candidate['user_id'] === $userId) {
                return $candidate['store_scoped'] === false
                    || $this->storeAccess->canAccessStore($userId, $store->store_number);
            }
        }

        return false;
    }

    /**
     * The reverse walk: every section this user is an assignee for, and whether
     * that grant is store-scoped. DOWN from each assigned level, so that listing
     * tickets does not resolve per row.
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

        // Same most-permissive collapse as the upward walk.
        $note = function (int $sectionId, bool $scoped) use (&$sections): void {
            $sections[$sectionId] = isset($sections[$sectionId]) ? ($sections[$sectionId] && $scoped) : $scoped;
        };

        foreach ($rows as $row) {
            $scoped = (bool) $row->store_scoped;

            if ($row->ticket_section_id !== null) {
                $note((int) $row->ticket_section_id, $scoped);

                continue;
            }

            $levelIds = $row->ticket_level_id === null ? [] : $this->descendantsOf((int) $row->ticket_level_id);

            if ($levelIds === []) {
                continue;
            }

            DB::table('ticket_level_section')
                ->whereIn('ticket_level_id', $levelIds)
                ->distinct()
                ->pluck('ticket_section_id')
                ->each(fn ($sectionId) => $note((int) $sectionId, $scoped));
        }

        return $sections;
    }

    /**
     * Every level whose assignees should reach this section: its own levels,
     * plus every ancestor of each.
     *
     * @return array<int, int>
     */
    private function levelIdsCovering(TicketSection $section): array
    {
        $reachable = [];

        foreach ($section->levels()->where('ticket_levels.active', true)->pluck('ticket_levels.id') as $levelId) {
            foreach ($this->ancestorsOf((int) $levelId) as $ancestorId) {
                // Keyed to dedupe: two of the section's levels may share an
                // ancestor, and that person should appear once.
                $reachable[$ancestorId] = true;
            }
        }

        return array_keys($reachable);
    }

    // -------------------------------------------------------------------------
    // The level graph
    //
    // Levels nest and a section may sit under several at once, so this is a
    // directed graph rather than a tree. The whole active catalogue is loaded
    // once and walked in memory: it is tens of rows, not a job for a recursive
    // CTE, and doing it in PHP is what gives the cycle guard somewhere to live.
    // -------------------------------------------------------------------------

    /**
     * The level itself plus every ancestor, nearest first.
     *
     * An inactive level is absent from the map entirely, which SEVERS the chain
     * rather than walking through it - deliberate, or `active` would mean
     * nothing mid-tree.
     *
     * @return array<int, int>
     */
    public function ancestorsOf(int $levelId): array
    {
        $parents = $this->parents();

        if (! array_key_exists($levelId, $parents)) {
            return [];
        }

        $chain = [];
        $visited = [];
        $current = $levelId;

        // The visited set is the seatbelt: a cycle introduced outside the API -
        // a seeder, a manual UPDATE, a restored backup - truncates the walk
        // here instead of looping forever.
        while ($current !== null && ! isset($visited[$current]) && count($chain) < self::MAX_DEPTH) {
            $visited[$current] = true;
            $chain[] = $current;
            $current = $parents[$current] ?? null;
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
        if (! array_key_exists($levelId, $this->parents())) {
            return [];
        }

        $children = $this->children ?? [];
        $found = [];
        $queue = [$levelId];

        for ($depth = 0; $queue !== [] && $depth < self::MAX_DEPTH; $depth++) {
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
        }

        return array_keys($found);
    }

    /**
     * Would making $candidateParentId the parent of $levelId create a cycle?
     * The PREVENTION half of the guard; the visited sets above contain what
     * gets past it. One prevents, one contains.
     */
    public function wouldCycle(int $levelId, ?int $candidateParentId): bool
    {
        if ($candidateParentId === null) {
            return false;
        }

        return $candidateParentId === $levelId
            || in_array($candidateParentId, $this->descendantsOf($levelId), true);
    }

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
     * @return array<int, int|null>
     */
    private function parents(): array
    {
        if ($this->parents !== null) {
            return $this->parents;
        }

        $parents = TicketLevel::query()
            ->where('active', true)
            ->pluck('parent_id', 'id')
            ->map(fn ($parentId) => $parentId === null ? null : (int) $parentId)
            ->all();

        $children = [];

        foreach ($parents as $id => $parentId) {
            // A parent that is itself inactive is treated as absent, so the
            // chain stops there rather than silently jumping the gap.
            if ($parentId !== null && ! array_key_exists($parentId, $parents)) {
                $parents[$id] = null;
            } elseif ($parentId !== null) {
                $children[$parentId][] = (int) $id;
            }
        }

        $this->children = $children;

        return $this->parents = $parents;
    }
}
