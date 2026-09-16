<?php

namespace App\Services\Tickets;

use App\Models\Ticket;
use App\Models\TicketResponse;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\Attachments\StagedUpload;
use Illuminate\Support\Facades\DB;

/**
 * The conversation on a ticket.
 */
class TicketResponseService
{
    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly TicketWriteService $tickets,
        private readonly TicketNotifier $notifier,
        private readonly TicketBroadcaster $broadcaster,
        private readonly TicketPresenter $presenter,
    ) {}

    /**
     * @param  array<int, StagedUpload>  $staged
     */
    public function create(User $author, Ticket $ticket, string $body, array $staged = []): TicketResponse
    {
        return DB::transaction(function () use ($author, $ticket, $body, $staged) {
            $response = $ticket->responses()->create([
                'user_id' => $author->id,
                'body' => $body,
            ]);

            $this->attachments->commit($response, $staged);

            // Set ONCE. "How long did the first reply take" is only meaningful
            // if a later reply cannot overwrite it.
            if ($ticket->first_responded_at === null) {
                $ticket->forceFill(['first_responded_at' => now()])->save();
            }

            $this->tickets->touchActivity($ticket);

            $response->load(['author', 'attachments.creator']);
            $ticket->load(['store', 'section', 'reporter', 'participants']);

            $this->notifier->responded($author, $ticket, $response);
            $this->broadcaster->responded($ticket, $response);

            return $response;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function present(TicketResponse $response): array
    {
        return $this->presenter->response($response);
    }
}
