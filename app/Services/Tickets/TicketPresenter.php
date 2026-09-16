<?php

namespace App\Services\Tickets;

use App\Models\Ticket;
use App\Models\TicketParticipant;
use App\Models\TicketResponse;
use App\Models\TicketStatusChange;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\Notes\NoteService;

/**
 * Turning ticket models into wire payloads.
 *
 * Deliberately separate from the services that WRITE, and it depends on none of
 * them. Presenting used to live on each service, which made a cycle the moment
 * the broadcaster needed to render a ticket: status service -> broadcaster ->
 * query service -> status service, and the container recursed until it ran out
 * of memory. A presenter that knows how to render but not how to change
 * anything cannot take part in that.
 */
class TicketPresenter
{
    public function __construct(
        private readonly TicketAccessResolver $access,
        private readonly AttachmentService $attachments,
        private readonly NoteService $notes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function ticket(Ticket $ticket, ?User $viewer = null, bool $full = false): array
    {
        $data = [
            'id' => $ticket->id,
            'title' => $ticket->title,
            'description' => $ticket->description,
            'status' => $ticket->status->value,
            'status_label' => $ticket->status->label(),
            'is_terminal' => $ticket->status->isTerminal(),
            // Handing back what IS possible turns a refused transition into a
            // set of buttons the client can render.
            'allowed_transitions' => array_map(
                fn ($s) => $s->value,
                $ticket->status->allowedTransitions(),
            ),
            'store' => $ticket->relationLoaded('store') && $ticket->store
                ? ['id' => $ticket->store->id, 'store_number' => $ticket->store->store_number, 'name' => $ticket->store->name]
                : null,
            'section' => $ticket->relationLoaded('section') && $ticket->section
                ? ['id' => $ticket->section->id, 'key' => $ticket->section->key, 'name' => $ticket->section->name]
                : null,
            'reporter' => $ticket->relationLoaded('reporter') && $ticket->reporter
                ? ['id' => $ticket->reporter->id, 'name' => $ticket->reporter->name]
                : ['id' => $ticket->reported_by],
            'first_responded_at' => $ticket->first_responded_at?->toIso8601String(),
            'fixed_at' => $ticket->fixed_at?->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
            'reopened_at' => $ticket->reopened_at?->toIso8601String(),
            'reopen_count' => $ticket->reopen_count,
            'last_activity_at' => $ticket->last_activity_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
        ];

        if ($ticket->relationLoaded('participants')) {
            $data['participants'] = $ticket->participants
                ->map(fn (TicketParticipant $p) => $this->participant($p))->all();
        }

        if ($full) {
            $data['responses'] = $ticket->relationLoaded('responses')
                ? $ticket->responses->map(fn (TicketResponse $r) => $this->response($r))->all()
                : null;
            $data['notes'] = $this->notes->presentMany($ticket);
            $data['attachments'] = $this->attachments->presentMany($ticket);
            $data['status_changes'] = $ticket->relationLoaded('statusChanges')
                ? $ticket->statusChanges->map(fn (TicketStatusChange $c) => $this->statusChange($c))->all()
                : null;
        }

        // VIEWER-NEUTRAL when $viewer is null, and broadcasts depend on that:
        // one envelope reaches many people, so embedding one recipient's
        // capabilities would show everyone the most-privileged person's buttons.
        if ($viewer !== null) {
            $data['viewer'] = $this->access->capabilities($viewer, $ticket);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function response(TicketResponse $response): array
    {
        return [
            'id' => $response->id,
            'body' => $response->body,
            'author' => $response->relationLoaded('author') && $response->author
                ? ['id' => $response->author->id, 'name' => $response->author->name]
                : ['id' => $response->user_id],
            'attachments' => $this->attachments->presentMany($response),
            'created_at' => $response->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function statusChange(TicketStatusChange $change): array
    {
        return [
            'id' => $change->id,
            'from' => $change->from_status?->value,
            'to' => $change->to_status->value,
            'to_label' => $change->to_status->label(),
            'is_reopen' => $change->is_reopen,
            'reason' => $change->reason,
            'created_by' => $change->created_by,
            'creator' => $change->relationLoaded('creator') && $change->creator
                ? ['id' => $change->creator->id, 'name' => $change->creator->name]
                : null,
            'created_at' => $change->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function participant(TicketParticipant $participant): array
    {
        return [
            'id' => $participant->id,
            'user' => $participant->relationLoaded('user') && $participant->user
                ? ['id' => $participant->user->id, 'name' => $participant->user->name, 'email' => $participant->user->email]
                : ['id' => $participant->user_id],
            'role' => $participant->role->value,
            'role_label' => $participant->role->label(),
            'added_by' => $participant->added_by,
            'created_at' => $participant->created_at?->toIso8601String(),
        ];
    }
}
