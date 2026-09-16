<?php

namespace App\Services\Tickets;

use App\Enums\TicketStatus;
use App\Jobs\PublishOutboxEventJob;
use App\Models\Ticket;
use App\Models\TicketParticipant;
use App\Models\TicketResponse;
use App\Models\TicketStatusChange;
use App\Models\ToolboxOutboxEvent;
use App\Models\User;
use App\Services\ToolboxEvents\ToolboxEventFactory;
use App\Services\ToolboxEvents\ToolboxOutboxService;
use Illuminate\Support\Str;

/**
 * Telling people about a ticket.
 *
 * Mirrors BreakNotifier: a CloudEvents envelope onto the outbox inside the
 * domain transaction, then PublishOutboxEventJob. NotificationsPizza consumes it
 * and writes the row that shows up in the bell.
 *
 * Recipients always come from TicketRecipientResolver, the same call the
 * broadcaster makes, so a notification and a broadcast can never disagree about
 * who was told.
 *
 * ON by default, unlike breaks: a ticket nobody is told about is not a ticket.
 */
class TicketNotifier
{
    public function __construct(
        private readonly ToolboxEventFactory $events,
        private readonly ToolboxOutboxService $outbox,
        private readonly TicketRecipientResolver $recipients,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('toolbox.tickets.notifications.enabled');
    }

    public function created(User $actor, Ticket $ticket): ?ToolboxOutboxEvent
    {
        return $this->announce($ticket, $actor, 'created', 'ticket_created', [
            'title' => 'New ticket: '.Str::limit($ticket->title, 80),
            'body' => sprintf(
                '%s opened a ticket in %s at store %s.',
                $actor->name,
                $ticket->section?->name ?? 'a section',
                $ticket->store?->store_number ?? '',
            ),
        ]);
    }

    public function responded(User $actor, Ticket $ticket, TicketResponse $response): ?ToolboxOutboxEvent
    {
        return $this->announce($ticket, $actor, 'responded', 'ticket_responded', [
            'title' => sprintf('New reply on "%s"', Str::limit($ticket->title, 60)),
            'body' => $actor->name.': '.Str::limit($response->body, 140),
        ], anchor: '#response-'.$response->id, extra: ['response_id' => $response->id]);
    }

    public function statusChanged(User $actor, Ticket $ticket, TicketStatusChange $change): ?ToolboxOutboxEvent
    {
        $to = $change->to_status;

        // Reopen, fixed and closed each read differently to the person getting
        // them, so they are not collapsed into one "status changed" message.
        [$type, $title, $body] = match (true) {
            $change->is_reopen => [
                'ticket_reopened',
                sprintf('"%s" was reopened', Str::limit($ticket->title, 60)),
                sprintf('%s reopened it: %s', $actor->name, (string) $change->reason),
            ],
            $to === TicketStatus::Fixed => [
                'ticket_fixed',
                sprintf('"%s" was marked fixed', Str::limit($ticket->title, 60)),
                sprintf('%s marked it fixed. If it is not, reopen it.', $actor->name),
            ],
            $to === TicketStatus::Closed => [
                'ticket_closed',
                sprintf('"%s" was closed', Str::limit($ticket->title, 60)),
                sprintf('%s closed it.', $actor->name),
            ],
            default => [
                'ticket_status_changed',
                sprintf('"%s" is now %s', Str::limit($ticket->title, 60), $to->label()),
                sprintf('%s moved it from %s to %s.', $actor->name, $change->from_status?->label() ?? 'new', $to->label())
                    .($change->reason ? ' Reason: '.$change->reason : ''),
            ],
        };

        return $this->announce($ticket, $actor, 'status_changed', $type, [
            'title' => $title,
            'body' => $body,
        ], extra: [
            'from' => $change->from_status?->value,
            'to' => $to->value,
            'is_reopen' => $change->is_reopen,
        ]);
    }

    /**
     * The one event with a recipient of exactly one person.
     */
    public function participantAdded(User $actor, Ticket $ticket, TicketParticipant $participant): ?ToolboxOutboxEvent
    {
        if (! $this->enabled() || (int) $participant->user_id === (int) $actor->id) {
            return null;
        }

        return $this->publish(
            $ticket,
            'participant_added',
            [(int) $participant->user_id],
            [
                'type' => 'ticket_participant_added',
                'title' => sprintf('You were added to "%s"', Str::limit($ticket->title, 60)),
                'body' => sprintf('%s added you as %s.', $actor->name, $participant->role->label()),
                'action_url' => $this->actionUrl($ticket),
            ],
            ['participant_user_id' => (int) $participant->user_id, 'role' => $participant->role->value],
        );
    }

    /**
     * @param  array<string, string>  $copy
     * @param  array<string, mixed>  $extra
     */
    private function announce(
        Ticket $ticket,
        User $actor,
        string $event,
        string $type,
        array $copy,
        string $anchor = '',
        array $extra = [],
    ): ?ToolboxOutboxEvent {
        if (! $this->enabled()) {
            return null;
        }

        $recipients = $this->audienceFor($ticket, $actor, $event);

        if ($recipients === []) {
            return null;
        }

        return $this->publish($ticket, $event, $recipients, [
            'type' => $type,
            'title' => $copy['title'],
            'body' => $copy['body'],
            'action_url' => $this->actionUrl($ticket).$anchor,
        ], $extra);
    }

    /**
     * Who hears about this.
     *
     * On creation: the people responsible for it. On everything after: the
     * reporter and the participants too, because by then they are following it -
     * readers included, which is what being a reader is for.
     *
     * THE ACTOR IS ALWAYS REMOVED. Nobody is told about their own action.
     *
     * @return array<int, int>
     */
    private function audienceFor(Ticket $ticket, User $actor, string $event): array
    {
        $assignees = $ticket->section !== null && $ticket->store !== null
            ? $this->recipients->assigneesFor($ticket->section, $ticket->store)
            : [];

        $participants = $ticket->participants()->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        $ids = $event === 'created'
            ? [...$assignees, ...$participants]
            : [...$assignees, ...$participants, (int) $ticket->reported_by];

        $ids = array_values(array_unique(array_filter(
            $ids,
            fn (int $id) => $id !== (int) $actor->id,
        )));

        sort($ids);

        return $ids;
    }

    /**
     * @param  array<int, int>  $recipients
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $extra
     */
    private function publish(Ticket $ticket, string $event, array $recipients, array $payload, array $extra = []): ToolboxOutboxEvent
    {
        // The domain event first. Its data records recipient_user_ids, so the
        // outbox stays an honest record of who was told AT THE TIME - assignment
        // is resolved dynamically, and the live answer moves.
        $domainSubject = "toolbox.v1.ticket.{$event}";

        $this->record($domainSubject, $this->events->make($domainSubject, [
            'ticket_id' => $ticket->id,
            'store_number' => $ticket->store?->store_number,
            'section_key' => $ticket->section?->key,
            'status' => $ticket->status->value,
            'recipient_user_ids' => $recipients,
        ] + $extra));

        // One envelope carrying every recipient, not one per person.
        $subject = 'notifications.v1.notification.send';

        return $this->record($subject, $this->events->make($subject, [
            'channels' => ['web'],
            'users' => array_map(fn (int $id) => ['id' => $id, 'data' => $payload], $recipients),
        ]));
    }

    private function record(string $subject, array $envelope): ToolboxOutboxEvent
    {
        $row = $this->outbox->record($subject, $envelope);

        // afterCommit, same as BreakNotifier: this runs inside the domain
        // transaction and every queue connection has after_commit => false.
        PublishOutboxEventJob::dispatch($row->id)->afterCommit();

        return $row;
    }

    private function actionUrl(Ticket $ticket): string
    {
        // From config, never hardcoded - this service does not own the
        // dashboard's routing.
        return rtrim((string) config('toolbox.tickets.action_url'), '/').'/'.$ticket->id;
    }
}
