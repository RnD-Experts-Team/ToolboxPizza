<?php

namespace App\Services\Tickets;

use App\Enums\TicketStatus;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reads and presenters for tickets.
 *
 * The presenter lives here rather than in a JsonResource, matching the two
 * newest sibling services.
 */
class TicketQueryService
{
    public function __construct(
        private readonly TicketRecipientResolver $recipients,
        private readonly StoreAccessResolver $storeAccess,
        private readonly TicketPresenter $presenter,
    ) {}

    /**
     * Tickets for one store, or across every store the caller can see.
     *
     * WHEN $store IS NULL this is the cross-store inbox, and pizzasys is NOT
     * adjudicating per store on that route - there is no store in the path to
     * adjudicate. The visibility clause below is therefore the only thing
     * standing between a caller and another store's tickets, which is exactly
     * why user_store_roles is replicated locally.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function index(User $viewer, array $filters = [], ?Store $store = null): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 200);

        $query = Ticket::query()
            ->with(['store', 'section', 'reporter', 'participants'])
            ->when($store !== null, fn (Builder $q) => $q->where('store_id', $store->id))
            ->when($store === null, fn (Builder $q) => $this->scopeToVisible($q, $viewer));

        $this->applyFilters($query, $filters);

        return $query
            // Most recently touched first, id as the tiebreaker so two tickets
            // sharing a last_activity_at keep a stable order across pages.
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (Ticket $t) => $this->present($t, $viewer));
    }

    /**
     * Narrow a query to what this user may see, without a query per row.
     *
     * Three ways in: they reported it, they are an explicit participant, or the
     * section/level routing reaches them for that store.
     */
    private function scopeToVisible(Builder $query, User $viewer): Builder
    {
        $sections = $this->recipients->assignedSectionsFor($viewer->id);
        $storeIds = $this->storeAccess->accessibleStoreIdsFor($viewer->id);

        // Sections reached by a store-scoped grant versus an unscoped one - the
        // first is limited to the stores they hold, the second is not.
        $scopedSectionIds = array_keys(array_filter($sections));
        $unscopedSectionIds = array_keys(array_filter($sections, fn (bool $scoped) => ! $scoped));

        return $query->where(function (Builder $q) use ($viewer, $scopedSectionIds, $unscopedSectionIds, $storeIds) {
            $q->where('reported_by', $viewer->id)
                ->orWhereHas('participants', fn (Builder $p) => $p->where('user_id', $viewer->id));

            if ($unscopedSectionIds !== []) {
                $q->orWhereIn('ticket_section_id', $unscopedSectionIds);
            }

            if ($scopedSectionIds !== []) {
                $q->orWhere(function (Builder $s) use ($scopedSectionIds, $storeIds) {
                    $s->whereIn('ticket_section_id', $scopedSectionIds);

                    // NULL means every store, so the clause is DROPPED rather
                    // than turned into a whereIn over the whole estate.
                    if ($storeIds !== null) {
                        $s->whereIn('store_id', $storeIds);
                    }
                });
            }
        });
    }

    /**
     * @param  Builder<Ticket>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        // ?statuses[]=pending&statuses[]=fixed - OR within a filter, AND across
        // them. An unparseable value is dropped silently rather than 422'd, the
        // house convention.
        $statuses = array_values(array_filter(array_map(
            fn ($v) => TicketStatus::tryFrom((string) $v),
            array_filter((array) ($filters['statuses'] ?? [])),
        )));

        if ($statuses !== []) {
            $query->whereIn('status', array_map(fn (TicketStatus $s) => $s->value, $statuses));
        }

        $sectionKeys = array_filter((array) ($filters['section_keys'] ?? []));

        if ($sectionKeys !== []) {
            $query->whereHas('section', fn (Builder $q) => $q->whereIn('key', $sectionKeys));
        }

        $sectionIds = array_filter((array) ($filters['section_ids'] ?? []));

        if ($sectionIds !== []) {
            $query->whereIn('ticket_section_id', $sectionIds);
        }

        // Store CODES, matching what the path and the checkboxes speak in.
        $stores = array_filter((array) ($filters['stores'] ?? []));

        if ($stores !== []) {
            $query->whereHas('store', fn (Builder $q) => $q->whereIn('store_number', $stores));
        }

        if (filled($filters['reported_by'] ?? null)) {
            $query->where('reported_by', $filters['reported_by']);
        }

        if (filled($filters['search'] ?? null)) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn (Builder $q) => $q->where('title', 'like', $term)->orWhere('description', 'like', $term));
        }
    }

    /**
     * Delegated: presentation lives in TicketPresenter, which depends on no
     * writing service and so cannot join a dependency cycle.
     *
     * @return array<string, mixed>
     */
    public function present(Ticket $ticket, ?User $viewer = null, bool $full = false): array
    {
        return $this->presenter->ticket($ticket, $viewer, $full);
    }
}
