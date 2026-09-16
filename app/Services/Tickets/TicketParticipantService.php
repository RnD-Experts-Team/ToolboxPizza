<?php

namespace App\Services\Tickets;

use App\Enums\TicketParticipantRole;
use App\Models\Ticket;
use App\Models\TicketParticipant;
use App\Models\User;
use App\Services\Tickets\Exceptions\TicketException;
use Illuminate\Database\Eloquent\Collection;

/**
 * Explicitly adding people to one ticket.
 */
class TicketParticipantService
{
    public function __construct(
        private readonly TicketAccessResolver $access,
        private readonly TicketNotifier $notifier,
        private readonly TicketBroadcaster $broadcaster,
        private readonly TicketPresenter $presenter,
    ) {}

    /**
     * @throws TicketException
     */
    public function add(
        User $actor,
        Ticket $ticket,
        int $userId,
        TicketParticipantRole $role,
        bool $enforcePermission = true,
    ): TicketParticipant {
        if ($enforcePermission) {
            $this->access->assertCan('manage participants of', $actor, $ticket);
        }

        if ((int) $ticket->reported_by === $userId) {
            // The reporter already reads and replies by virtue of reporting.
            // A row saying so would be a second source of truth that could
            // later disagree with the first.
            throw TicketException::participantRedundant('The reporter is already on this ticket.');
        }

        // One row per user: re-adding CHANGES the role rather than stacking a
        // second grant, so a downgrade is expressible.
        $participant = TicketParticipant::query()->updateOrCreate(
            ['ticket_id' => $ticket->id, 'user_id' => $userId],
            ['role' => $role, 'added_by' => $actor->id],
        );

        $ticket->forceFill(['last_activity_at' => now()])->save();

        $participant->load('user');
        $ticket->load(['store', 'section', 'reporter', 'participants']);

        $this->notifier->participantAdded($actor, $ticket, $participant);
        $this->broadcaster->ticketChanged($ticket, TicketBroadcaster::PARTICIPANTS_CHANGED);

        return $participant;
    }

    /**
     * @throws TicketException
     */
    public function remove(User $actor, Ticket $ticket, int $userId): void
    {
        $this->access->assertCan('manage participants of', $actor, $ticket);

        TicketParticipant::query()
            ->where('ticket_id', $ticket->id)
            ->where('user_id', $userId)
            ->delete();
    }

    /**
     * @return Collection<int, TicketParticipant>
     */
    public function forTicket(Ticket $ticket): Collection
    {
        return $ticket->participants()->with('user')->orderBy('id')->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(TicketParticipant $participant): array
    {
        return $this->presenter->participant($participant);
    }
}
