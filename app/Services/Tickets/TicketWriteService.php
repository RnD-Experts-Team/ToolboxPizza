<?php

namespace App\Services\Tickets;

use App\Enums\TicketParticipantRole;
use App\Enums\TicketStatus;
use App\Models\Note;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\Attachments\StagedUpload;
use App\Services\Notes\NoteService;
use App\Services\Tickets\Exceptions\TicketException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Raising and editing tickets.
 *
 * Also the ONLY writer of last_activity_at - every other service calls
 * touchActivity() rather than setting the column, so there is one place that
 * decides what counts as activity.
 */
class TicketWriteService
{
    public function __construct(
        private readonly TicketSectionService $sections,
        private readonly AttachmentService $attachments,
        private readonly NoteService $notes,
        private readonly TicketParticipantService $participants,
        private readonly TicketNotifier $notifier,
        private readonly TicketBroadcaster $broadcaster,
    ) {}

    /**
     * Raise a ticket.
     *
     * Files arrive already staged on disk - written OUTSIDE this transaction so
     * a rollback cannot orphan them. The controller discards them if we throw.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, StagedUpload>  $staged
     * @param  array<int, array<int, StagedUpload>>  $stagedNoteFiles
     *
     * @throws TicketException
     */
    public function create(User $reporter, Store $store, array $data, array $staged = [], array $stagedNoteFiles = []): Ticket
    {
        $section = $this->sections->resolveByKey((string) $data['section_key'], forNewTicket: true);

        return DB::transaction(function () use ($reporter, $store, $section, $data, $staged, $stagedNoteFiles) {
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
                $this->notes->store($ticket, $note['body'], $note['type'] ?? null, $stagedNoteFiles[$i] ?? []);
            }

            foreach ($data['participants'] ?? [] as $participant) {
                $this->participants->add(
                    $reporter,
                    $ticket,
                    (int) $participant['user_id'],
                    TicketParticipantRole::from($participant['role']),
                    // The reporter is allowed to loop people in at creation;
                    // the permission check that normally guards this belongs to
                    // the endpoint, not to raising your own ticket.
                    enforcePermission: false,
                );
            }

            // Inside the transaction, so a notification can never fire for a
            // ticket that rolled back.
            $ticket->load(['store', 'section', 'reporter', 'participants']);

            $this->notifier->created($reporter, $ticket);
            $this->broadcaster->ticketChanged($ticket, TicketBroadcaster::CREATED);

            return $ticket;
        });
    }

    /**
     * Correct a ticket. Title, description and section only - status has its own
     * endpoint because it carries an audit row and a reason.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws TicketException
     */
    public function update(Ticket $ticket, array $data): Ticket
    {
        return DB::transaction(function () use ($ticket, $data) {
            $update = array_intersect_key($data, array_flip(['title', 'description']));

            if (array_key_exists('section_key', $data)) {
                // Re-sectioning re-routes the ticket: the next event resolves a
                // different audience. Deliberate - that is what moving it means.
                $section = $this->sections->resolveByKey((string) $data['section_key'], forNewTicket: true);
                $update['ticket_section_id'] = $section->id;
            }

            if ($update !== []) {
                $ticket->fill($update);
                $ticket->last_activity_at = now();
                $ticket->save();

                $this->broadcaster->ticketChanged(
                    $ticket->load(['store', 'section', 'reporter', 'participants']),
                    TicketBroadcaster::UPDATED,
                );
            }

            return $ticket->refresh();
        });
    }

    /**
     * @param  array<int, StagedUpload>  $staged
     */
    public function addNote(User $author, Ticket $ticket, string $body, array $staged = []): Note
    {
        return DB::transaction(function () use ($ticket, $body, $staged) {
            $note = $this->notes->store($ticket, $body, null, $staged);

            $this->touchActivity($ticket);

            return $note;
        });
    }

    /**
     * @param  array<int, StagedUpload>  $staged
     */
    public function addAttachments(Ticket $ticket, array $staged): array
    {
        return DB::transaction(function () use ($ticket, $staged) {
            $created = $this->attachments->commit($ticket, $staged);

            $this->touchActivity($ticket);

            return $created;
        });
    }

    /**
     * The single owner of last_activity_at.
     */
    public function touchActivity(Ticket $ticket, ?CarbonInterface $at = null): void
    {
        $ticket->forceFill(['last_activity_at' => $at ?? now()])->save();
    }

    /**
     * Remove a ticket and everything hanging off it.
     *
     * Responses and participants cascade, but NOTES AND ATTACHMENTS ARE
     * POLYMORPHIC and cascade nothing - they have to be removed by hand, here
     * and on the response path, or they accumulate forever pointing at an id
     * that no longer exists.
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
}
