<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketStatus;
use App\Http\Controllers\Api\V1\Concerns\ResolvesStore;
use App\Http\Controllers\Api\V1\Concerns\ResolvesVisibleTicket;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeTicketStatusRequest;
use App\Http\Requests\ReopenTicketRequest;
use App\Services\Tickets\TicketAccessResolver;
use App\Services\Tickets\TicketQueryService;
use App\Services\Tickets\TicketStatusService;

class TicketStatusController extends Controller
{
    use ResolvesStore, ResolvesVisibleTicket;

    public function __construct(
        private readonly TicketStatusService $statuses,
        private readonly TicketQueryService $reader,
        private readonly TicketAccessResolver $access,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function update(ChangeTicketStatusRequest $request, string $storeId, int $ticketId): array
    {
        $ticket = $this->visibleTicket($this->resolveStore($storeId), $ticketId);

        $this->access->assertCan('change status', $request->user(), $ticket);

        $this->statuses->change(
            $request->user(),
            $ticket,
            TicketStatus::from($request->validated('status')),
            $request->validated('reason'),
        );

        return ['data' => $this->reader->present(
            $ticket->refresh()->load(['store', 'section', 'reporter', 'participants.user']),
            $request->user(),
        )];
    }

    /**
     * Its own endpoint rather than just another status value: reopening always
     * needs a reason, and it means something different to everyone watching.
     *
     * @return array<string, mixed>
     */
    public function reopen(ReopenTicketRequest $request, string $storeId, int $ticketId): array
    {
        $ticket = $this->visibleTicket($this->resolveStore($storeId), $ticketId);

        $this->access->assertCan('change status', $request->user(), $ticket);

        $this->statuses->reopen($request->user(), $ticket, (string) $request->validated('reason'));

        return ['data' => $this->reader->present(
            $ticket->refresh()->load(['store', 'section', 'reporter', 'participants.user']),
            $request->user(),
        )];
    }
}
