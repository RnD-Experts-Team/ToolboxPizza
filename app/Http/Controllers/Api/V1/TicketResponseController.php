<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesStore;
use App\Http\Controllers\Api\V1\Concerns\ResolvesVisibleTicket;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTicketResponseRequest;
use App\Services\Attachments\AttachmentService;
use App\Services\Tickets\TicketAccessResolver;
use App\Services\Tickets\TicketResponseService;
use Illuminate\Http\JsonResponse;
use Throwable;

class TicketResponseController extends Controller
{
    use ResolvesStore, ResolvesVisibleTicket;

    public function __construct(
        private readonly TicketResponseService $responses,
        private readonly TicketAccessResolver $access,
        private readonly AttachmentService $attachments,
    ) {}

    public function store(StoreTicketResponseRequest $request, string $storeId, int $ticketId): JsonResponse
    {
        $ticket = $this->visibleTicket($this->resolveStore($storeId), $ticketId);

        $this->access->assertCan('respond', $request->user(), $ticket);

        // Staged outside the transaction so a rollback cannot orphan the bytes.
        $staged = $this->attachments->stage((array) $request->file('files', []));

        try {
            $response = $this->responses->create(
                $request->user(),
                $ticket,
                (string) $request->validated('body'),
                $staged,
            );
        } catch (Throwable $e) {
            $this->attachments->discard($staged);

            throw $e;
        }

        return response()->json(['data' => $this->responses->present($response)], 201);
    }
}
