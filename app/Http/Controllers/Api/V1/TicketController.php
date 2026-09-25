<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketParticipantRole;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TicketAttachmentRequest;
use App\Http\Requests\Api\V1\TicketNoteRequest;
use App\Http\Requests\Api\V1\TicketParticipantRequest;
use App\Http\Requests\Api\V1\TicketReopenRequest;
use App\Http\Requests\Api\V1\TicketResponseRequest;
use App\Http\Requests\Api\V1\TicketStatusRequest;
use App\Http\Requests\Api\V1\TicketStoreRequest;
use App\Http\Requests\Api\V1\TicketUpdateRequest;
use App\Models\Attachment;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketParticipant;
use App\Services\Tickets\TicketAccessService;
use App\Services\Tickets\TicketQueryService;
use App\Services\Tickets\TicketService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Raising, reading and working tickets.
 *
 * Store-scoped routes carry the store CODE ("03795-00001"). A ticket from
 * another store is a 404 under this store's path, and so is a ticket the caller
 * has no relationship to - a 403 would confirm the id exists. Every other
 * refusal is a 403, because by then the caller can already see the thing.
 */
class TicketController extends Controller
{
    private const RELATIONS = ['store', 'section', 'reporter', 'participants.user'];

    public function __construct(
        private readonly TicketService $tickets,
        private readonly TicketQueryService $reader,
        private readonly TicketAccessService $access,
    ) {}

    // -------------------------------------------------------------------------
    // Lists
    // -------------------------------------------------------------------------

    /**
     * The cross-store inbox. Self-scoped in the service, because pizzasys makes
     * no per-store decision here - there is no store in the path to judge.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function inbox(Request $request): LengthAwarePaginator
    {
        return $this->reader->index($request->user(), $request->only([
            'statuses', 'section_keys', 'section_ids', 'stores', 'reported_by', 'search', 'per_page',
        ]));
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function index(Request $request, string $storeId): LengthAwarePaginator
    {
        return $this->reader->index(
            $request->user(),
            $request->only(['statuses', 'section_keys', 'section_ids', 'reported_by', 'search', 'per_page']),
            Store::resolveByNumber($storeId),
        );
    }

    // -------------------------------------------------------------------------
    // One ticket
    // -------------------------------------------------------------------------

    public function store(TicketStoreRequest $request, string $storeId): JsonResponse
    {
        $store = Store::resolveByNumber($storeId);
        $data = $request->validated();

        $noteFiles = [];

        foreach (array_keys($data['notes'] ?? []) as $i) {
            $noteFiles[$i] = (array) $request->file("notes.{$i}.files", []);
        }

        $ticket = $this->tickets->create($request->user(), $store, $data, (array) $request->file('files', []), $noteFiles);
        $ticket->load(self::RELATIONS);

        return response()->json([
            'data' => $this->reader->present($ticket, $request->user(), full: true) + [
                // Not an error when empty - refusing the ticket would throw away
                // someone's report because of an admin's configuration gap.
                'warnings' => $this->access->assigneesFor($ticket->section, $store) === [] ? ['no_recipients'] : [],
            ],
        ], 201);
    }

    public function show(Request $request, string $storeId, int $ticketId): JsonResponse
    {
        $ticket = $this->visible($request, $storeId, $ticketId)->load([
            'responses.author', 'responses.attachments.creator',
            'notes.attachments.creator', 'notes.creator',
            'attachments.creator', 'statusChanges.creator',
        ]);

        return response()->json(['data' => $this->reader->present($ticket, $request->user(), full: true)]);
    }

    public function update(TicketUpdateRequest $request, string $storeId, int $ticketId): JsonResponse
    {
        $ticket = $this->tickets->update($request->user(), $this->visible($request, $storeId, $ticketId), $request->validated());

        return $this->presented($request, $ticket->load(self::RELATIONS));
    }

    public function changeStatus(TicketStatusRequest $request, string $storeId, int $ticketId): JsonResponse
    {
        $ticket = $this->visible($request, $storeId, $ticketId);

        $this->tickets->changeStatus(
            $request->user(),
            $ticket,
            TicketStatus::from($request->validated('status')),
            $request->validated('reason'),
        );

        return $this->presented($request, $ticket->refresh()->load(self::RELATIONS));
    }

    /**
     * Its own endpoint rather than just another status value: reopening always
     * needs a reason, and it means something different to everyone watching.
     */
    public function reopen(TicketReopenRequest $request, string $storeId, int $ticketId): JsonResponse
    {
        $ticket = $this->visible($request, $storeId, $ticketId);

        $this->tickets->reopen($request->user(), $ticket, (string) $request->validated('reason'));

        return $this->presented($request, $ticket->refresh()->load(self::RELATIONS));
    }

    /**
     * Who this ticket reaches, and why - so an admin looking at a surprising
     * recipient list can see whether each person came from the section itself
     * or from a level above it.
     */
    public function recipients(Request $request, string $storeId, int $ticketId): JsonResponse
    {
        $ticket = $this->visible($request, $storeId, $ticketId);

        return response()->json(['data' => [
            'user_ids' => $this->access->assigneesFor($ticket->section, $ticket->store),
            'candidates' => $this->access->candidatesFor($ticket->section),
        ]]);
    }

    // -------------------------------------------------------------------------
    // The thread
    // -------------------------------------------------------------------------

    public function respond(TicketResponseRequest $request, string $storeId, int $ticketId): JsonResponse
    {
        $response = $this->tickets->respond(
            $request->user(),
            $this->visible($request, $storeId, $ticketId),
            (string) $request->validated('body'),
            (array) $request->file('files', []),
        );

        return response()->json(['data' => $this->reader->presentResponse($response)], 201);
    }

    public function storeNote(TicketNoteRequest $request, string $storeId, int $ticketId): JsonResponse
    {
        $note = $this->tickets->addNote(
            $request->user(),
            $this->visible($request, $storeId, $ticketId),
            (string) $request->validated('body'),
            (array) $request->file('files', []),
        );

        return response()->json(['data' => $this->reader->presentNote($note)], 201);
    }

    public function storeAttachments(TicketAttachmentRequest $request, string $storeId, int $ticketId): JsonResponse
    {
        $created = $this->tickets->addAttachments(
            $request->user(),
            $this->visible($request, $storeId, $ticketId),
            (array) $request->file('files', []),
        );

        return response()->json([
            'data' => array_map(fn (Attachment $a) => $this->reader->presentAttachment($a), $created),
        ], 201);
    }

    // -------------------------------------------------------------------------
    // Participants
    // -------------------------------------------------------------------------

    public function participants(Request $request, string $storeId, int $ticketId): JsonResponse
    {
        $ticket = $this->visible($request, $storeId, $ticketId);

        return response()->json(['data' => $ticket->participants()->with('user')->orderBy('id')->get()
            ->map(fn (TicketParticipant $p) => $this->reader->presentParticipant($p))
            ->all()]);
    }

    /**
     * Re-adding someone changes their role rather than stacking a second grant.
     */
    public function addParticipant(TicketParticipantRequest $request, string $storeId, int $ticketId): JsonResponse
    {
        $participant = $this->tickets->addParticipant(
            $request->user(),
            $this->visible($request, $storeId, $ticketId),
            (int) $request->validated('user_id'),
            TicketParticipantRole::from($request->validated('role')),
        );

        return response()->json(['data' => $this->reader->presentParticipant($participant)], 201);
    }

    public function removeParticipant(Request $request, string $storeId, int $ticketId, int $userId): Response
    {
        $this->tickets->removeParticipant($request->user(), $this->visible($request, $storeId, $ticketId), $userId);

        return response()->noContent();
    }

    private function visible(Request $request, string $storeId, int $ticketId): Ticket
    {
        return $this->access->findVisible($request->user(), Store::resolveByNumber($storeId), $ticketId);
    }

    private function presented(Request $request, Ticket $ticket): JsonResponse
    {
        return response()->json(['data' => $this->reader->present($ticket, $request->user())]);
    }
}
