<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesStore;
use App\Http\Controllers\Api\V1\Concerns\ResolvesVisibleTicket;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTicketNoteRequest;
use App\Services\Attachments\AttachmentService;
use App\Services\Notes\NoteService;
use App\Services\Tickets\TicketAccessResolver;
use App\Services\Tickets\TicketWriteService;
use Illuminate\Http\JsonResponse;
use Throwable;

class TicketNoteController extends Controller
{
    use ResolvesStore, ResolvesVisibleTicket;

    public function __construct(
        private readonly TicketWriteService $writer,
        private readonly NoteService $notes,
        private readonly TicketAccessResolver $access,
        private readonly AttachmentService $attachments,
    ) {}

    public function store(StoreTicketNoteRequest $request, string $storeId, int $ticketId): JsonResponse
    {
        $ticket = $this->visibleTicket($this->resolveStore($storeId), $ticketId);

        // Same authority as replying: a note remarks on the ticket rather than
        // changing it.
        $this->access->assertCan('respond', $request->user(), $ticket);

        $staged = $this->attachments->stage((array) $request->file('files', []));

        try {
            $note = $this->writer->addNote($request->user(), $ticket, (string) $request->validated('body'), $staged);
        } catch (Throwable $e) {
            $this->attachments->discard($staged);

            throw $e;
        }

        return response()->json(['data' => $this->notes->present($note)], 201);
    }
}
