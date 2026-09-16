<?php

namespace App\Services\Tickets;

use App\Enums\TicketParticipantRole;
use App\Enums\TicketStatus;
use App\Enums\TicketViewerRole;
use App\Models\Ticket;
use App\Models\TicketParticipant;
use App\Models\User;
use App\Services\Tickets\Exceptions\TicketException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Who may do what to a ticket. THE single source of truth for the permission
 * matrix - controllers ask, they never decide.
 *
 * Two sources of authority are combined, and the HIGHEST wins: the implicit
 * routing (reporter, or an assignee the section/level+store resolution reaches)
 * and the explicit participant rows. So a reporter who also happens to be an
 * assignee keeps the assignee's powers rather than being demoted by being the
 * one who reported it.
 */
class TicketAccessResolver
{
    public function __construct(private readonly TicketRecipientResolver $recipients) {}

    public function roleFor(User $user, Ticket $ticket): TicketViewerRole
    {
        $participant = $this->participantRole($user, $ticket);

        // An explicit assignee outranks everything - it is the deliberate
        // override for "this one needs Dana".
        if ($participant === TicketParticipantRole::Assignee) {
            return TicketViewerRole::ExceptionAssignee;
        }

        $section = $ticket->relationLoaded('section') ? $ticket->section : $ticket->section()->first();
        $store = $ticket->relationLoaded('store') ? $ticket->store : $ticket->store()->first();

        if ($section !== null && $store !== null && $this->recipients->isAssignee($user->id, $section, $store)) {
            return TicketViewerRole::Assignee;
        }

        if ((int) $ticket->reported_by === (int) $user->id) {
            return TicketViewerRole::Reporter;
        }

        return match ($participant) {
            TicketParticipantRole::Responder => TicketViewerRole::Responder,
            TicketParticipantRole::Reader => TicketViewerRole::Reader,
            default => TicketViewerRole::None,
        };
    }

    public function canView(User $user, Ticket $ticket): bool
    {
        return $this->roleFor($user, $ticket)->canView();
    }

    public function canRespond(User $user, Ticket $ticket): bool
    {
        return $this->roleFor($user, $ticket)->canRespond();
    }

    public function canChangeStatus(User $user, Ticket $ticket): bool
    {
        return $this->roleFor($user, $ticket)->canChangeStatus();
    }

    public function canManageParticipants(User $user, Ticket $ticket): bool
    {
        return $this->roleFor($user, $ticket)->canManageParticipants();
    }

    /**
     * Editing the title or description.
     *
     * An assignee may always. The reporter may only while the ticket is still
     * Pending: once somebody has picked it up, silently rewriting the
     * description invalidates what they acted on.
     */
    public function canEdit(User $user, Ticket $ticket): bool
    {
        $role = $this->roleFor($user, $ticket);

        if ($role->canChangeStatus()) {
            return true;
        }

        return $role === TicketViewerRole::Reporter && $ticket->status === TicketStatus::Pending;
    }

    /**
     * View is the ONLY ability that 404s. A 403 would confirm the ticket exists,
     * which is enough to probe for them - the same reasoning as ResolvesOwnBreak.
     *
     * @throws ModelNotFoundException
     */
    public function assertCanView(User $user, Ticket $ticket): void
    {
        if (! $this->canView($user, $ticket)) {
            throw (new ModelNotFoundException)->setModel(Ticket::class, [$ticket->id]);
        }
    }

    /**
     * Every other ability 403s, because the caller can already see the thing -
     * hiding it at that point would just be confusing.
     *
     * @throws TicketException
     */
    public function assertCan(string $ability, User $user, Ticket $ticket): void
    {
        $allowed = match ($ability) {
            'respond' => $this->canRespond($user, $ticket),
            'change status' => $this->canChangeStatus($user, $ticket),
            'manage participants of' => $this->canManageParticipants($user, $ticket),
            'edit' => $this->canEdit($user, $ticket),
            default => false,
        };

        if (! $allowed) {
            throw TicketException::forbidden($ability, $this->roleFor($user, $ticket));
        }
    }

    /**
     * What the dashboard should render. Handing the client the same answers the
     * server would give means the buttons on screen are exactly the ones that
     * will work.
     *
     * @return array<string, mixed>
     */
    public function capabilities(User $user, Ticket $ticket): array
    {
        $role = $this->roleFor($user, $ticket);

        return [
            'role' => $role->value,
            'role_label' => $role->label(),
            'can' => [
                'view' => $role->canView(),
                'respond' => $role->canRespond(),
                'change_status' => $role->canChangeStatus(),
                'manage_participants' => $role->canManageParticipants(),
                'edit' => $this->canEdit($user, $ticket),
            ],
        ];
    }

    private function participantRole(User $user, Ticket $ticket): ?TicketParticipantRole
    {
        $participants = $ticket->relationLoaded('participants')
            ? $ticket->participants
            : $ticket->participants()->get();

        $row = $participants->first(fn (TicketParticipant $p) => (int) $p->user_id === (int) $user->id);

        return $row?->role;
    }
}
