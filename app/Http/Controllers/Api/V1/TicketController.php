<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesStore;
use App\Http\Controllers\Api\V1\Concerns\ResolvesVisibleTicket;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTicketRequest;
use App\Http\Requests\UpdateTicketRequest;
use App\Services\Attachments\AttachmentService;
use App\Services\Tickets\TicketAccessResolver;
use App\Services\Tickets\TicketQueryService;
use App\Services\Tickets\TicketRecipientResolver;
use App\Services\Tickets\TicketWriteService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class TicketController extends Controller
{
    use ResolvesStore, ResolvesVisibleTicket;

    public function __construct(
        private readonly TicketWriteService $writer,
        private readonly TicketQueryService $reader,
        private readonly TicketAccessResolver $access,
        private readonly TicketRecipientResolver $recipients,
        private readonly AttachmentService $attachments,
    ) {}

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function index(Request $request, string $storeId): LengthAwarePaginator
    {
        return $this->reader->index(
            $request->user(),
            $request->only(['statuses', 'section_keys', 'section_ids', 'reported_by', 'search', 'per_page']),
            $this->resolveStore($storeId),
        );
    }

    /**
     * The cross-store inbox.
     *
     * Self-scoped in the service, because pizzasys is NOT making a per-store
     * decision on this route - there is no store in the path for it to judge.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function inbox(Request $request): LengthAwarePaginator
    {
        return $this->reader->index(
            $request->user(),
            $request->only(['statuses', 'section_keys', 'section_ids', 'stores', 'reported_by', 'search', 'per_page']),
        );
    }

    public function store(StoreTicketRequest $request, string $storeId): JsonResponse
    {
        $store = $this->resolveStore($storeId);
        $data = $request->validated();

        // Bytes are written BEFORE the transaction opens, so a rollback can
        // never strand them - the catch below unlinks what the transaction did
        // not keep. MaintenancePizza uploads inside the transaction and leaks
        // on every failure.
        $staged = $this->attachments->stage((array) $request->file('files', []));
        $stagedNotes = [];

        foreach ($data['notes'] ?? [] as $i => $note) {
            $stagedNotes[$i] = $this->attachments->stage((array) $request->file("notes.{$i}.files", []));
        }

        try {
            $ticket = $this->writer->create($request->user(), $store, $data, $staged, $stagedNotes);
        } catch (Throwable $e) {
            $this->attachments->discard(array_merge($staged, ...array_values($stagedNotes)));

            throw $e;
        }

        $ticket->load(['store', 'section', 'reporter', 'participants.user']);

        $recipients = $this->recipients->assigneesFor($ticket->section, $store);

        return response()->json([
            'data' => $this->reader->present($ticket, $request->user(), full: true) + [
                // Not an error when empty - refusing the ticket would throw away
                // someone's report because of an admin's configuration gap.
                'warnings' => $recipients === [] ? ['no_recipients'] : [],
            ],
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    public function show(Request $request, string $storeId, int $ticketId): array
    {
        $ticket = $this->visibleTicket($this->resolveStore($storeId), $ticketId);

        $ticket->load(['responses.author', 'responses.attachments.creator', 'notes.attachments.creator', 'notes.creator', 'attachments.creator', 'statusChanges.creator']);

        return ['data' => $this->reader->present($ticket, $request->user(), full: true)];
    }

    /**
     * @return array<string, mixed>
     */
    public function update(UpdateTicketRequest $request, string $storeId, int $ticketId): array
    {
        $ticket = $this->visibleTicket($this->resolveStore($storeId), $ticketId);
        $data = $request->validated();

        $this->access->assertCan('edit', $request->user(), $ticket);

        // Re-sectioning re-routes the ticket to a different audience, so it is
        // an assignee's call rather than the reporter's - even while the
        // reporter may still edit the wording.
        if (array_key_exists('section_key', $data)) {
            $this->access->assertCan('change status', $request->user(), $ticket);
        }

        $ticket = $this->writer->update($ticket, $data);

        return ['data' => $this->reader->present(
            $ticket->load(['store', 'section', 'reporter', 'participants.user']),
            $request->user(),
        )];
    }

    /**
     * Who this ticket reaches, and why.
     *
     * Makes the resolution observable: an admin looking at a surprising
     * recipient list can see whether each person came from the section itself or
     * from a level above it.
     *
     * @return array<string, mixed>
     */
    public function recipients(Request $request, string $storeId, int $ticketId): array
    {
        $store = $this->resolveStore($storeId);
        $ticket = $this->visibleTicket($store, $ticketId);

        $resolved = $this->recipients->assigneesFor($ticket->section, $store);

        return ['data' => [
            'user_ids' => $resolved,
            'candidates' => $this->recipients->candidatesFor($ticket->section),
        ]];
    }
}
