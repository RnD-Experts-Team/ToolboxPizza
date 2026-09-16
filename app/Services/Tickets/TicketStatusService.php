<?php

namespace App\Services\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketStatusChange;
use App\Models\User;
use App\Services\Tickets\Exceptions\TicketException;
use Illuminate\Support\Facades\DB;

/**
 * Moving a ticket, and the only writer of ticket_status_changes.
 *
 * Owns transition legality and the set-once timestamps. Permission to move it at
 * all is TicketAccessResolver's business, checked before this is reached.
 */
class TicketStatusService
{
    public function __construct(
        private readonly TicketNotifier $notifier,
        private readonly TicketBroadcaster $broadcaster,
        private readonly TicketPresenter $presenter,
    ) {}

    /**
     * @throws TicketException
     */
    public function change(User $actor, Ticket $ticket, TicketStatus $to, ?string $reason = null): TicketStatusChange
    {
        $from = $ticket->status;

        if ($from === $to) {
            // A double-clicked button, not a state change. Refused rather than
            // written as an audit row that says nothing happened.
            throw TicketException::alreadyInStatus($to);
        }

        if (! $from->canTransitionTo($to)) {
            throw TicketException::illegalTransition($from, $to);
        }

        $isReopen = $from->isReopenEdge($to);

        if ($isReopen && ($reason === null || trim($reason) === '')) {
            throw TicketException::reopenReasonRequired();
        }

        return DB::transaction(function () use ($actor, $ticket, $from, $to, $reason, $isReopen) {
            $change = $ticket->statusChanges()->create([
                'from_status' => $from,
                'to_status' => $to,
                'is_reopen' => $isReopen,
                'reason' => $reason,
                'created_by' => $actor->id,
            ]);

            $ticket->status = $to;
            $ticket->last_activity_at = now();

            if ($isReopen) {
                // Coming back out of a terminal state clears the marks that said
                // it was finished, or a reopened ticket would still read as
                // fixed on any screen sorting by those.
                $ticket->fixed_at = null;
                $ticket->closed_at = null;
                $ticket->reopened_at = now();
                $ticket->reopen_count = $ticket->reopen_count + 1;
            }

            if ($to === TicketStatus::Fixed) {
                $ticket->fixed_at = now();
            }

            if ($to === TicketStatus::Closed) {
                $ticket->closed_at = now();
            }

            $ticket->save();

            $ticket->load(['store', 'section', 'reporter', 'participants']);

            $this->notifier->statusChanged($actor, $ticket, $change);
            $this->broadcaster->statusChanged($ticket, $change);

            return $change;
        });
    }

    /**
     * Reopen: the one edge out of a terminal state, and the one that always
     * demands a reason.
     *
     * @throws TicketException
     */
    public function reopen(User $actor, Ticket $ticket, string $reason): TicketStatusChange
    {
        if (! $ticket->status->isTerminal()) {
            throw TicketException::notReopenable($ticket->status);
        }

        return $this->change($actor, $ticket, TicketStatus::Pending, $reason);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(TicketStatusChange $change): array
    {
        return $this->presenter->statusChange($change);
    }
}
