<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesStore;
use App\Http\Controllers\Api\V1\Concerns\ResolvesVisibleTicket;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTicketAttachmentRequest;
use App\Models\Attachment;
use App\Services\Attachments\AttachmentService;
use App\Services\Tickets\TicketAccessResolver;
use App\Services\Tickets\TicketWriteService;
use Illuminate\Http\JsonResponse;
use Throwable;

class TicketAttachmentController extends Controller
{
    use ResolvesStore, ResolvesVisibleTicket;

    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly TicketAccessResolver $access,
        private readonly TicketWriteService $writer,
    ) {}

    public function store(StoreTicketAttachmentRequest $request, string $storeId, int $ticketId): JsonResponse
    {
        $ticket = $this->visibleTicket($this->resolveStore($storeId), $ticketId);

        $this->access->assertCan('respond', $request->user(), $ticket);

        $staged = $this->attachments->stage((array) $request->file('files', []));

        try {
            $created = $this->writer->addAttachments($ticket, $staged);
        } catch (Throwable $e) {
            $this->attachments->discard($staged);

            throw $e;
        }

        return response()->json([
            'data' => array_map(fn (Attachment $a) => $this->attachments->present($a), $created),
        ], 201);
    }
}
