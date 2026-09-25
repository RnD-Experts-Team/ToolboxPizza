<?php

namespace App\Services\Tickets;

use App\Enums\TicketParticipantRole;
use App\Enums\TicketStatus;
use App\Exceptions\TicketException;
use App\Models\Attachment;
use App\Models\Note;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketParticipant;
use App\Models\TicketResponse;
use App\Models\TicketStatusChange;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\ToolboxEvents\ToolboxOutboxService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Every write to a ticket, and who is told about it.
 *
 * Each mutating method takes the acting user and checks their ability first,
 * so a forbidden request never writes a byte. Notifications and live
 * broadcasts are recorded INSIDE the write's transaction, so neither can ever
 * fire for a change that rolled back.
 *
 * Also the ONLY writer of last_activity_at, so there is one place that decides
 * what counts as activity.
 */
class TicketService
{
    public function __construct(
        private readonly TicketAccessService $access,
        private readonly TicketCatalogService $catalog,
        private readonly TicketQueryService $reader,
        private readonly AttachmentService $attachments,
        private readonly ToolboxOutboxService $outbox,
    ) {}

    // -------------------------------------------------------------------------
    // Raising and editing
    // -------------------------------------------------------------------------

    /**
     * Raise a ticket.
     *
     * Files are written to disk BEFORE the transaction opens and unlinked again
     * if it fails - including each note's own files - so a rollback can never
     * strand bytes.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $files  on the ticket itself
     * @param  array<int, array<int, UploadedFile>>  $noteFiles  per note, paired with $data['notes'] by index
     *
     * @throws TicketException
     */
    public function create(User $reporter, Store $store, array $data, array $files = [], array $noteFiles = []): Ticket
    {
        $section = $this->catalog->resolveSection((string) $data['section_key']);

        $staged = $this->attachments->stage($files);
        $stagedNotes = array_map(fn ($group) => $this->attachments->stage((array) $group), $noteFiles);

        try {
            return DB::transaction(function () use ($reporter, $store, $section, $data, $staged, $stagedNotes) {
                $ticket = Ticket::query()->create([
                    'store_id' => $store->id,
                    'ticket_section_id' => $section->id,
                    'title' => $data['title'],
                    'description' => $data['description'],
                    'reported_by' => $reporter->id,
                    'status' => TicketStatus::Pending,
                    'last_activity_at' => now(),
                ]);

                // The audit is complete from birth: a creation row with a null
                // from_status, so reading the history never starts mid-story.
                $ticket->statusChanges()->create([
                    'from_status' => null,
                    'to_status' => TicketStatus::Pending,
                    'created_by' => $reporter->id,
                ]);

                $this->attachments->commit($ticket, $staged);

                foreach ($data['notes'] ?? [] as $i => $note) {
                    $this->storeNote($reporter, $ticket, $note['body'], $stagedNotes[$i] ?? []);
                }

                foreach ($data['participants'] ?? [] as $participant) {
                    // The reporter may loop people in at creation; the check
                    // that normally guards this belongs to the endpoint, not to
                    // raising your own ticket.
                    $this->addParticipant(
                        $reporter,
                        $ticket,
                        (int) $participant['user_id'],
                        TicketParticipantRole::from($participant['role']),
                        enforcePermission: false,
                    );
                }

                $this->reload($ticket);

                $this->notify($ticket, $reporter, 'ticket_created', [
                    'title' => 'New ticket: '.Str::limit($ticket->title, 80),
                    'body' => sprintf(
                        '%s opened a ticket in %s at store %s.',
                        $reporter->name,
                        $ticket->section?->name ?? 'a section',
                        $ticket->store?->store_number ?? '',
                    ),
                ], includeReporter: false);

                $this->broadcast($ticket, 'ticket.created');

                return $ticket;
            });
        } catch (Throwable $e) {
            $this->attachments->discard(array_merge($staged, ...array_values($stagedNotes)));

            throw $e;
        }
    }

    /**
     * Title, description and section. Status has its own endpoint because it
     * carries an audit row and a reason.
     *
     * Re-sectioning re-routes the ticket to a different audience, so it is an
     * assignee's call rather than the reporter's - even while the reporter may
     * still edit the wording.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws TicketException
     */
    public function update(User $actor, Ticket $ticket, array $data): Ticket
    {
        $this->access->assertCan('edit', $actor, $ticket);

        if (array_key_exists('section_key', $data)) {
            $this->access->assertCan('change status', $actor, $ticket);
        }

        return DB::transaction(function () use ($ticket, $data) {
            $update = array_intersect_key($data, array_flip(['title', 'description']));

            if (array_key_exists('section_key', $data)) {
                $update['ticket_section_id'] = $this->catalog->resolveSection((string) $data['section_key'])->id;
            }

            if ($update !== []) {
                $ticket->fill($update);
                $ticket->last_activity_at = now();
                $ticket->save();

                $this->broadcast($this->reload($ticket), 'ticket.updated');
            }

            return $ticket->refresh();
        });
    }

    // -------------------------------------------------------------------------
    // Status
    // -------------------------------------------------------------------------

    /**
     * Move a ticket. The only writer of ticket_status_changes, and the owner of
     * transition legality and the set-once timestamps.
     *
     * @throws TicketException
     */
    public function changeStatus(User $actor, Ticket $ticket, TicketStatus $to, ?string $reason = null): TicketStatusChange
    {
        $this->access->assertCan('change status', $actor, $ticket);

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
            $this->reload($ticket);

            $this->notifyStatusChanged($actor, $ticket, $change);

            $this->broadcast($ticket, 'ticket.status_changed', [
                'from' => $change->from_status?->value,
                'to' => $change->to_status->value,
                'is_reopen' => $change->is_reopen,
            ]);

            return $change;
        });
    }

    /**
     * The one edge out of a terminal state, and the one that always demands a
     * reason - it means something different to everyone watching.
     *
     * @throws TicketException
     */
    public function reopen(User $actor, Ticket $ticket, string $reason): TicketStatusChange
    {
        $this->access->assertCan('change status', $actor, $ticket);

        if (! $ticket->status->isTerminal()) {
            throw TicketException::notReopenable($ticket->status);
        }

        return $this->changeStatus($actor, $ticket, TicketStatus::Pending, $reason);
    }

    // -------------------------------------------------------------------------
    // The thread
    // -------------------------------------------------------------------------

    /**
     * @param  array<int, UploadedFile>  $files
     *
     * @throws TicketException
     */
    public function respond(User $author, Ticket $ticket, string $body, array $files = []): TicketResponse
    {
        $this->access->assertCan('respond', $author, $ticket);

        return $this->attachments->withStaged($files, fn (array $staged) => DB::transaction(function () use ($author, $ticket, $body, $staged) {
            $response = $ticket->responses()->create(['user_id' => $author->id, 'body' => $body]);

            $this->attachments->commit($response, $staged);

            // Set ONCE. "How long did the first reply take" is only meaningful
            // if a later reply cannot overwrite it.
            if ($ticket->first_responded_at === null) {
                $ticket->forceFill(['first_responded_at' => now()])->save();
            }

            $this->touchActivity($ticket);

            $response->load(['author', 'attachments.creator']);
            $this->reload($ticket);

            $this->notify($ticket, $author, 'ticket_responded', [
                'title' => sprintf('New reply on "%s"', Str::limit($ticket->title, 60)),
                'body' => $author->name.': '.Str::limit($response->body, 140),
            ], anchor: '#response-'.$response->id);

            $this->broadcast($ticket, 'ticket.responded', ['response' => $this->reader->presentResponse($response)]);

            return $response;
        }));
    }

    /**
     * Same authority as replying: a note remarks on the ticket rather than
     * changing it.
     *
     * @param  array<int, UploadedFile>  $files
     *
     * @throws TicketException
     */
    public function addNote(User $author, Ticket $ticket, string $body, array $files = []): Note
    {
        $this->access->assertCan('respond', $author, $ticket);

        return $this->attachments->withStaged($files, fn (array $staged) => DB::transaction(function () use ($author, $ticket, $body, $staged) {
            $note = $this->storeNote($author, $ticket, $body, $staged);

            $this->touchActivity($ticket);

            return $note;
        }));
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, Attachment>
     *
     * @throws TicketException
     */
    public function addAttachments(User $actor, Ticket $ticket, array $files): array
    {
        $this->access->assertCan('respond', $actor, $ticket);

        return $this->attachments->withStaged($files, fn (array $staged) => DB::transaction(function () use ($ticket, $staged) {
            $created = $this->attachments->commit($ticket, $staged);

            $this->touchActivity($ticket);

            return $created;
        }));
    }

    // -------------------------------------------------------------------------
    // Participants
    // -------------------------------------------------------------------------

    /**
     * Adding someone is a routing decision, so it needs the manage-participants
     * ability.
     *
     * @throws TicketException
     */
    public function addParticipant(
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
            // The reporter already reads and replies by virtue of reporting. A
            // row saying so would be a second source of truth that could later
            // disagree with the first.
            throw TicketException::participantRedundant('The reporter is already on this ticket.');
        }

        return DB::transaction(function () use ($actor, $ticket, $userId, $role) {
            // One row per user: re-adding CHANGES the role rather than stacking
            // a second grant, so a downgrade is expressible.
            $participant = TicketParticipant::query()->updateOrCreate(
                ['ticket_id' => $ticket->id, 'user_id' => $userId],
                ['role' => $role, 'added_by' => $actor->id],
            );

            $this->touchActivity($ticket);

            $participant->load('user');
            $this->reload($ticket);

            // The one notification with a recipient of exactly one person - and
            // nobody is told about their own action.
            if ($this->notificationsEnabled() && $userId !== (int) $actor->id) {
                $this->outbox->notify([$userId], [
                    'type' => 'ticket_participant_added',
                    'title' => sprintf('You were added to "%s"', Str::limit($ticket->title, 60)),
                    'body' => sprintf('%s added you as %s.', $actor->name, $participant->role->label()),
                    'action_url' => $this->actionUrl($ticket),
                ]);
            }

            $this->broadcast($ticket, 'ticket.participants_changed');

            return $participant;
        });
    }

    /**
     * @throws TicketException
     */
    public function removeParticipant(User $actor, Ticket $ticket, int $userId): void
    {
        $this->access->assertCan('manage participants of', $actor, $ticket);

        TicketParticipant::query()->where('ticket_id', $ticket->id)->where('user_id', $userId)->delete();
    }

    // -------------------------------------------------------------------------
    // Removal
    // -------------------------------------------------------------------------

    /**
     * Remove a ticket and everything hanging off it. No route reaches this yet.
     *
     * Responses and participants cascade, but NOTES AND ATTACHMENTS ARE
     * POLYMORPHIC and cascade nothing - they are removed by hand here, or they
     * would accumulate forever pointing at an id that no longer exists.
     */
    public function delete(Ticket $ticket): void
    {
        DB::transaction(function () use ($ticket) {
            foreach ($ticket->responses()->withTrashed()->get() as $response) {
                $this->attachments->purgeFor($response);
                $response->forceDelete();
            }

            foreach ($ticket->notes()->withTrashed()->get() as $note) {
                $this->attachments->purgeFor($note);
                $note->forceDelete();
            }

            $this->attachments->purgeFor($ticket);

            $ticket->forceDelete();
        });
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $staged
     */
    private function storeNote(User $author, Ticket $ticket, string $body, array $staged): Note
    {
        $note = $ticket->notes()->make(['body' => $body]);
        $note->created_by = $author->id;
        $note->save();

        $this->attachments->commit($note, $staged);

        return $note->load(['attachments.creator', 'creator']);
    }

    private function touchActivity(Ticket $ticket): void
    {
        $ticket->forceFill(['last_activity_at' => now()])->save();
    }

    /**
     * Fresh relations for presenting and for resolving who hears about it.
     */
    private function reload(Ticket $ticket): Ticket
    {
        return $ticket->load(['store', 'section', 'reporter', 'participants']);
    }

    // -------------------------------------------------------------------------
    // Notifications - what lands in each recipient's bell
    //
    // ON by default (TOOLBOX_TICKET_NOTIFICATIONS_ENABLED), unlike breaks: a
    // ticket nobody is told about is not a ticket. The strings here are exactly
    // what the frontend receives.
    // -------------------------------------------------------------------------

    /**
     * Reopen, fixed and closed each read differently to the person getting
     * them, so they are not collapsed into one "status changed" message.
     */
    private function notifyStatusChanged(User $actor, Ticket $ticket, TicketStatusChange $change): void
    {
        $to = $change->to_status;
        $title = Str::limit($ticket->title, 60);

        [$type, $headline, $body] = match (true) {
            $change->is_reopen => [
                'ticket_reopened',
                sprintf('"%s" was reopened', $title),
                sprintf('%s reopened it: %s', $actor->name, (string) $change->reason),
            ],
            $to === TicketStatus::Fixed => [
                'ticket_fixed',
                sprintf('"%s" was marked fixed', $title),
                sprintf('%s marked it fixed. If it is not, reopen it.', $actor->name),
            ],
            $to === TicketStatus::Closed => [
                'ticket_closed',
                sprintf('"%s" was closed', $title),
                sprintf('%s closed it.', $actor->name),
            ],
            default => [
                'ticket_status_changed',
                sprintf('"%s" is now %s', $title, $to->label()),
                sprintf('%s moved it from %s to %s.', $actor->name, $change->from_status?->label() ?? 'new', $to->label())
                    .($change->reason ? ' Reason: '.$change->reason : ''),
            ],
        };

        $this->notify($ticket, $actor, $type, ['title' => $headline, 'body' => $body]);
    }

    /**
     * Tell everyone following the ticket.
     *
     * On creation: the people responsible for it. After that: the reporter and
     * the participants too - readers included, which is what being a reader is
     * for. THE ACTOR IS ALWAYS REMOVED: nobody is told about their own action.
     *
     * @param  array{title: string, body: string}  $copy
     */
    private function notify(Ticket $ticket, User $actor, string $type, array $copy, string $anchor = '', bool $includeReporter = true): void
    {
        if (! $this->notificationsEnabled()) {
            return;
        }

        $recipients = array_values(array_filter(
            $this->audience($ticket, $includeReporter),
            fn (int $id) => $id !== (int) $actor->id,
        ));

        if ($recipients === []) {
            return;
        }

        $this->outbox->notify($recipients, [
            'type' => $type,
            'title' => $copy['title'],
            'body' => $copy['body'],
            'action_url' => $this->actionUrl($ticket).$anchor,
        ]);
    }

    private function notificationsEnabled(): bool
    {
        return (bool) config('toolbox.tickets.notifications.enabled');
    }

    /**
     * From config, never hardcoded - this service does not own the dashboard's
     * routing.
     */
    private function actionUrl(Ticket $ticket): string
    {
        return rtrim((string) config('toolbox.tickets.action_url'), '/').'/'.$ticket->id;
    }

    // -------------------------------------------------------------------------
    // Live state - pushed to open ticket views
    // -------------------------------------------------------------------------

    /**
     * Push the ticket's new state to everyone watching it. OFF by default
     * (TOOLBOX_TICKET_REALTIME_ENABLED).
     *
     * Two deliberate differences from notify(): the ACTOR IS INCLUDED, because
     * their other tabs and devices need to converge too; and the ticket is
     * presented VIEWER-NEUTRAL, with no `viewer` key - one envelope reaches many
     * people, and embedding one recipient's capabilities would show a reader the
     * Close button the server then refuses. A test guards that absence.
     *
     * @param  array<string, mixed>  $extra
     */
    private function broadcast(Ticket $ticket, string $event, array $extra = []): void
    {
        if (! config('toolbox.tickets.realtime.enabled')) {
            return;
        }

        $recipients = $this->audience($ticket, includeReporter: true);

        if ($recipients === []) {
            return;
        }

        $this->outbox->broadcast($recipients, $event, [
            'ticket_id' => $ticket->id,
            ...$extra,
            'ticket' => $this->reader->present($ticket, viewer: null),
        ]);
    }

    /**
     * Assignees for the ticket's section and store, plus its participants, plus
     * (usually) its reporter. Participants are re-read rather than taken from
     * the loaded relation, so a change made in this same request is included.
     *
     * @return array<int, int>
     */
    private function audience(Ticket $ticket, bool $includeReporter): array
    {
        $ids = [
            ...($ticket->section !== null && $ticket->store !== null
                ? $this->access->assigneesFor($ticket->section, $ticket->store)
                : []),
            ...$ticket->participants()->pluck('user_id')->map(fn ($id) => (int) $id)->all(),
        ];

        if ($includeReporter) {
            $ids[] = (int) $ticket->reported_by;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
