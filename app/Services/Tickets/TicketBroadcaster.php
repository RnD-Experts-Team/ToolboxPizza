<?php

namespace App\Services\Tickets;

use App\Jobs\PublishOutboxEventJob;
use App\Models\Ticket;
use App\Models\TicketResponse;
use App\Models\TicketStatusChange;
use App\Models\ToolboxOutboxEvent;
use App\Models\User;
use App\Services\ToolboxEvents\ToolboxEventFactory;
use App\Services\ToolboxEvents\ToolboxOutboxService;

/**
 * Live ticket state on the user's socket.
 *
 * Rides NotificationsPizza's Reverb on the private-users.{id} channel the
 * dashboard already holds, via the transient notifications.v1.broadcast.send
 * path - so an open ticket view updates itself without polling, and without
 * filling the notification bell.
 *
 * OFF by default. The transport only works once NotificationsPizza is carrying
 * BroadcastSendHandler; enabling this first would make its consumer park the
 * messages.
 */
class TicketBroadcaster
{
    public const CREATED = 'ticket.created';

    public const UPDATED = 'ticket.updated';

    public const RESPONDED = 'ticket.responded';

    public const STATUS_CHANGED = 'ticket.status_changed';

    public const PARTICIPANTS_CHANGED = 'ticket.participants_changed';

    public function __construct(
        private readonly ToolboxEventFactory $events,
        private readonly ToolboxOutboxService $outbox,
        private readonly TicketRecipientResolver $recipients,
        // TicketPresenter, not TicketQueryService: the query service reaches the
        // writing services for presentation, and depending on it from here made
        // status service -> broadcaster -> query service -> status service, which
        // the container followed until it ran out of memory.
        private readonly TicketPresenter $presenter,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('toolbox.tickets.realtime.enabled');
    }

    public function ticketChanged(Ticket $ticket, string $event): ?ToolboxOutboxEvent
    {
        return $this->push($ticket, $event, [
            'ticket_id' => $ticket->id,
            'ticket' => $this->neutralTicket($ticket),
        ]);
    }

    public function responded(Ticket $ticket, TicketResponse $response): ?ToolboxOutboxEvent
    {
        return $this->push($ticket, self::RESPONDED, [
            'ticket_id' => $ticket->id,
            'response' => $this->presenter->response($response),
            'ticket' => $this->neutralTicket($ticket),
        ]);
    }

    public function statusChanged(Ticket $ticket, TicketStatusChange $change): ?ToolboxOutboxEvent
    {
        return $this->push($ticket, self::STATUS_CHANGED, [
            'ticket_id' => $ticket->id,
            'from' => $change->from_status?->value,
            'to' => $change->to_status->value,
            'is_reopen' => $change->is_reopen,
            'ticket' => $this->neutralTicket($ticket),
        ]);
    }

    /**
     * VIEWER-NEUTRAL, and this is load-bearing.
     *
     * One envelope goes to many people. Presenting the ticket for a particular
     * viewer would embed THAT person's capabilities and show everyone the
     * most-privileged recipient's buttons - a reader would see a Close button
     * the server would then refuse. A test guards the absence of this key.
     *
     * @return array<string, mixed>
     */
    private function neutralTicket(Ticket $ticket): array
    {
        return $this->presenter->ticket($ticket, viewer: null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function push(Ticket $ticket, string $event, array $data): ?ToolboxOutboxEvent
    {
        if (! $this->enabled()) {
            return null;
        }

        $recipients = $this->audienceFor($ticket);

        if ($recipients === []) {
            return null;
        }

        $subject = 'notifications.v1.broadcast.send';

        $row = $this->outbox->record($subject, $this->events->make($subject, [
            'event' => $event,
            'users' => array_map(fn (int $id) => ['id' => $id, 'data' => $data], $recipients),
        ]));

        PublishOutboxEventJob::dispatch($row->id)->afterCommit();

        return $row;
    }

    /**
     * Everyone watching the ticket - the actor included, because their other
     * tabs and devices need to converge too. That is the opposite of the
     * notifier, which always drops the actor.
     *
     * @return array<int, int>
     */
    private function audienceFor(Ticket $ticket): array
    {
        $assignees = $ticket->section !== null && $ticket->store !== null
            ? $this->recipients->assigneesFor($ticket->section, $ticket->store)
            : [];

        $participants = $ticket->participants()->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        $ids = array_values(array_unique([...$assignees, ...$participants, (int) $ticket->reported_by]));
        sort($ids);

        return $ids;
    }
}
