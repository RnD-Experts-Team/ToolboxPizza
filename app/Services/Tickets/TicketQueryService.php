<?php

namespace App\Services\Tickets;

use App\Enums\TicketStatus;
use App\Models\Attachment;
use App\Models\Note;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketParticipant;
use App\Models\TicketResponse;
use App\Models\TicketStatusChange;
use App\Models\User;
use App\Services\StoreAccessResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Every read in the tickets module, and how it is presented.
 *
 * Depends on nothing that writes, which is what keeps it out of dependency
 * cycles: TicketService presents through this for its broadcasts.
 */
class TicketQueryService
{
    public function __construct(
        private readonly TicketAccessService $access,
        private readonly StoreAccessResolver $storeAccess,
    ) {}

    /**
     * Tickets for one store, or across every store the caller can see.
     *
     * WHEN $store IS NULL this is the cross-store inbox, and pizzasys is NOT
     * adjudicating per store on that route - there is no store in the path to
     * adjudicate. The visibility clause is then the only thing standing between
     * a caller and another store's tickets, which is exactly why user_store_roles
     * is replicated locally.
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
     * Narrow a query to what this user may see, without a query per row. Three
     * ways in: they reported it, they are an explicit participant, or the
     * section/level routing reaches them for that store.
     */
    private function scopeToVisible(Builder $query, User $viewer): Builder
    {
        $sections = $this->access->assignedSectionsFor($viewer->id);
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
     * ?statuses[]=pending&statuses[]=fixed - OR within a filter, AND across
     * them. An unparseable value is dropped silently rather than 422'd, the
     * house convention.
     *
     * @param  Builder<Ticket>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $statuses = array_values(array_filter(array_map(
            fn ($v) => TicketStatus::tryFrom((string) $v)?->value,
            array_filter((array) ($filters['statuses'] ?? [])),
        )));

        $query
            ->when($statuses !== [], fn (Builder $q) => $q->whereIn('status', $statuses))
            ->when(
                array_filter((array) ($filters['section_keys'] ?? [])) !== [],
                fn (Builder $q) => $q->whereHas('section', fn (Builder $s) => $s->whereIn('key', array_filter((array) $filters['section_keys']))),
            )
            ->when(
                array_filter((array) ($filters['section_ids'] ?? [])) !== [],
                fn (Builder $q) => $q->whereIn('ticket_section_id', array_filter((array) $filters['section_ids'])),
            )
            // Store CODES, matching what the path and the checkboxes speak in.
            ->when(
                array_filter((array) ($filters['stores'] ?? [])) !== [],
                fn (Builder $q) => $q->whereHas('store', fn (Builder $s) => $s->whereIn('store_number', array_filter((array) $filters['stores']))),
            )
            ->when(filled($filters['reported_by'] ?? null), fn (Builder $q) => $q->where('reported_by', $filters['reported_by']))
            ->when(filled($filters['search'] ?? null), function (Builder $q) use ($filters) {
                $term = '%'.$filters['search'].'%';
                $q->where(fn (Builder $w) => $w->where('title', 'like', $term)->orWhere('description', 'like', $term));
            });
    }

    // -------------------------------------------------------------------------
    // Presentation
    // -------------------------------------------------------------------------

    /**
     * VIEWER-NEUTRAL when $viewer is null, and broadcasts depend on that: one
     * envelope reaches many people, so embedding one recipient's capabilities
     * would show everyone the most-privileged person's buttons.
     *
     * $full adds the thread; each of its keys is null when its relation was not
     * loaded, so a partial load is visible rather than silently empty.
     *
     * @return array<string, mixed>
     */
    public function present(Ticket $ticket, ?User $viewer = null, bool $full = false): array
    {
        $data = [
            'id' => $ticket->id,
            'title' => $ticket->title,
            'description' => $ticket->description,
            'status' => $ticket->status->value,
            'status_label' => $ticket->status->label(),
            'is_terminal' => $ticket->status->isTerminal(),
            // Handing back what IS possible turns a refused transition into a
            // set of buttons the client can render.
            'allowed_transitions' => array_map(fn (TicketStatus $s) => $s->value, $ticket->status->allowedTransitions()),
            'store' => $ticket->relationLoaded('store') && $ticket->store
                ? ['id' => $ticket->store->id, 'store_number' => $ticket->store->store_number, 'name' => $ticket->store->name]
                : null,
            'section' => $ticket->relationLoaded('section') && $ticket->section
                ? ['id' => $ticket->section->id, 'key' => $ticket->section->key, 'name' => $ticket->section->name]
                : null,
            'reporter' => $ticket->relationLoaded('reporter') && $ticket->reporter
                ? ['id' => $ticket->reporter->id, 'name' => $ticket->reporter->name]
                : ['id' => $ticket->reported_by],
            'first_responded_at' => $ticket->first_responded_at?->toIso8601String(),
            'fixed_at' => $ticket->fixed_at?->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
            'reopened_at' => $ticket->reopened_at?->toIso8601String(),
            'reopen_count' => $ticket->reopen_count,
            'last_activity_at' => $ticket->last_activity_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
        ];

        if ($ticket->relationLoaded('participants')) {
            $data['participants'] = $ticket->participants->map(fn (TicketParticipant $p) => $this->presentParticipant($p))->all();
        }

        if ($full) {
            $data['responses'] = $ticket->relationLoaded('responses')
                ? $ticket->responses->map(fn (TicketResponse $r) => $this->presentResponse($r))->all()
                : null;
            $data['notes'] = $ticket->relationLoaded('notes')
                ? $ticket->notes->map(fn (Note $n) => $this->presentNote($n))->all()
                : null;
            $data['attachments'] = $this->presentAttachments($ticket);
            $data['status_changes'] = $ticket->relationLoaded('statusChanges')
                ? $ticket->statusChanges->map(fn (TicketStatusChange $c) => $this->presentStatusChange($c))->all()
                : null;
        }

        if ($viewer !== null) {
            $data['viewer'] = $this->access->capabilities($viewer, $ticket);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentResponse(TicketResponse $response): array
    {
        return [
            'id' => $response->id,
            'body' => $response->body,
            'author' => $response->relationLoaded('author') && $response->author
                ? ['id' => $response->author->id, 'name' => $response->author->name]
                : ['id' => $response->user_id],
            'attachments' => $this->presentAttachments($response),
            'created_at' => $response->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentStatusChange(TicketStatusChange $change): array
    {
        return [
            'id' => $change->id,
            'from' => $change->from_status?->value,
            'to' => $change->to_status->value,
            'to_label' => $change->to_status->label(),
            'is_reopen' => $change->is_reopen,
            'reason' => $change->reason,
            'created_by' => $change->created_by,
            'creator' => $change->relationLoaded('creator') && $change->creator
                ? ['id' => $change->creator->id, 'name' => $change->creator->name]
                : null,
            'created_at' => $change->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentParticipant(TicketParticipant $participant): array
    {
        return [
            'id' => $participant->id,
            'user' => $participant->relationLoaded('user') && $participant->user
                ? ['id' => $participant->user->id, 'name' => $participant->user->name, 'email' => $participant->user->email]
                : ['id' => $participant->user_id],
            'role' => $participant->role->value,
            'role_label' => $participant->role->label(),
            'added_by' => $participant->added_by,
            'created_at' => $participant->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentNote(Note $note): array
    {
        return [
            'id' => $note->id,
            'type' => $note->type,
            'body' => $note->body,
            'attachments' => $this->presentAttachments($note),
            'created_by' => $note->created_by,
            'creator' => $note->relationLoaded('creator') && $note->creator
                ? ['id' => $note->creator->id, 'name' => $note->creator->name]
                : null,
            'created_at' => $note->created_at?->toIso8601String(),
            'updated_at' => $note->updated_at?->toIso8601String(),
        ];
    }

    /**
     * `url` is a plain public URL - there is no download endpoint.
     *
     * @return array<string, mixed>
     */
    public function presentAttachment(Attachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'url' => $attachment->url,
            'original_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size' => $attachment->size,
            'created_by' => $attachment->created_by,
            'creator' => $attachment->relationLoaded('creator') && $attachment->creator
                ? ['id' => $attachment->creator->id, 'name' => $attachment->creator->name]
                : null,
            'created_at' => $attachment->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>|null null when not loaded
     */
    private function presentAttachments(Model $owner): ?array
    {
        return $owner->relationLoaded('attachments')
            ? $owner->attachments->map(fn (Attachment $a) => $this->presentAttachment($a))->all()
            : null;
    }
}
