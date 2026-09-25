<?php

namespace Tests\Feature\Tickets;

use App\Models\Attachment;
use App\Models\Note;
use App\Models\Ticket;
use App\Models\TicketResponse;
use App\Models\User;
use App\Services\Tickets\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTicketWorld;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * Files and annotations on a ticket.
 *
 * Three of these guard defects inherited from MaintenancePizza rather than
 * features: created_by silently dropped, an unbounded upload surface on a
 * publicly-served disk, and a per-note file list that exists at one endpoint but
 * not at creation.
 */
class TicketNotesAndAttachmentsTest extends TestCase
{
    use BuildsTicketWorld, FakesAuthServer, RefreshDatabase;

    private User $reporter;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->buildTicketWorld();

        $this->reporter = $this->makeUser(40, 'Reporter');
        $this->grantStore($this->reporter);
        $this->fakeAuthServer($this->reporter);

        config([
            'toolbox.tickets.notifications.enabled' => false,
            'toolbox.tickets.realtime.enabled' => false,
        ]);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/stores/{$this->store->store_number}/tickets{$suffix}";
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'section_key' => $this->section->key,
            'title' => 'Fryer is leaking',
            'description' => 'Puddle under it.',
        ], $extra);
    }

    private function raise(): Ticket
    {
        $id = $this->post($this->url(), $this->payload(), $this->headers())->assertCreated()->json('data.id');

        return Ticket::query()->findOrFail($id);
    }

    // ---- index pairing ---------------------------------------------------

    /**
     * The one that is easy to get wrong: notes[1].files[] must land on the
     * SECOND note, and the top-level files[] on the ticket itself. Getting the
     * index pairing wrong attaches a photo to the wrong remark, which nobody
     * would notice until it mattered.
     */
    public function test_top_level_files_and_per_note_files_land_on_their_own_owners(): void
    {
        $this->post($this->url(), $this->payload([
            'files' => [UploadedFile::fake()->image('ticket.jpg')],
            'notes' => [
                ['body' => 'First note', 'files' => [UploadedFile::fake()->image('first-a.jpg'), UploadedFile::fake()->image('first-b.jpg')]],
                ['body' => 'Second note', 'files' => [UploadedFile::fake()->image('second.jpg')]],
            ],
        ]), $this->headers())->assertCreated();

        $ticket = Ticket::query()->firstOrFail();

        $this->assertSame(['ticket.jpg'], $ticket->attachments()->pluck('original_name')->all());

        $notes = Note::query()->orderBy('id')->get();

        $this->assertSame('First note', $notes[0]->body);
        $this->assertSame(['first-a.jpg', 'first-b.jpg'], $notes[0]->attachments()->pluck('original_name')->all());

        $this->assertSame('Second note', $notes[1]->body);
        $this->assertSame(['second.jpg'], $notes[1]->attachments()->pluck('original_name')->all());
    }

    /**
     * A note with no files is still a note - the index must not shift when one
     * entry omits the key.
     */
    public function test_a_note_without_files_does_not_shift_the_others(): void
    {
        $this->post($this->url(), $this->payload([
            'notes' => [
                ['body' => 'No files here'],
                ['body' => 'This one has one', 'files' => [UploadedFile::fake()->image('second.jpg')]],
            ],
        ]), $this->headers())->assertCreated();

        $notes = Note::query()->orderBy('id')->get();

        $this->assertSame(0, $notes[0]->attachments()->count());
        $this->assertSame(['second.jpg'], $notes[1]->attachments()->pluck('original_name')->all());
    }

    // ---- the created_by regression --------------------------------------

    /**
     * MaintenancePizza passes created_by to create() with the column absent from
     * $fillable, so it is silently dropped and every attachment there has a null
     * uploader. Guarded on all three owners, because each one commits by a
     * different path.
     */
    public function test_created_by_is_persisted_on_every_kind_of_attachment(): void
    {
        $this->post($this->url(), $this->payload([
            'files' => [UploadedFile::fake()->image('ticket.jpg')],
            'notes' => [['body' => 'Note', 'files' => [UploadedFile::fake()->image('note.jpg')]]],
        ]), $this->headers())->assertCreated();

        $ticket = Ticket::query()->firstOrFail();

        $this->post($this->url("/{$ticket->id}/responses"), [
            'body' => 'Here is a photo',
            'files' => [UploadedFile::fake()->image('response.jpg')],
        ], $this->headers())->assertCreated();

        $this->assertSame(3, Attachment::query()->count());
        $this->assertSame(0, Attachment::query()->whereNull('created_by')->count());

        foreach (Attachment::query()->get() as $attachment) {
            $this->assertSame($this->reporter->id, (int) $attachment->created_by);
        }
    }

    public function test_a_note_records_its_author(): void
    {
        $ticket = $this->raise();

        $this->post($this->url("/{$ticket->id}/notes"), ['body' => 'Called the engineer'], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.created_by', $this->reporter->id);

        $this->assertDatabaseHas('notes', ['body' => 'Called the engineer', 'created_by' => $this->reporter->id]);
    }

    // ---- what is recorded -----------------------------------------------

    /**
     * The disk is recorded per row rather than assumed from config, so moving to
     * a private disk later cannot strand the files already on the public one.
     */
    public function test_an_attachment_records_its_disk_checksum_and_sniffed_type(): void
    {
        $ticket = $this->raise();

        $this->post($this->url("/{$ticket->id}/attachments"), [
            'files' => [UploadedFile::fake()->image('receipt.jpg')],
        ], $this->headers())->assertCreated();

        $attachment = Attachment::query()->firstOrFail();

        $this->assertSame('public', $attachment->disk);
        $this->assertSame('image/jpeg', $attachment->mime_type);
        $this->assertSame(64, strlen((string) $attachment->checksum));
        $this->assertGreaterThan(0, $attachment->size);

        Storage::disk('public')->assertExists($attachment->path);
    }

    /**
     * The URL is embedded in the JSON and served off the storage:link symlink,
     * matching MaintenancePizza. That also means THE BYTES ARE UNAUTHENTICATED -
     * recorded here so the property is visible rather than incidental.
     */
    public function test_the_response_carries_a_public_url(): void
    {
        $ticket = $this->raise();

        $url = $this->post($this->url("/{$ticket->id}/attachments"), [
            'files' => [UploadedFile::fake()->image('receipt.jpg')],
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.0.original_name', 'receipt.jpg')
            ->assertJsonPath('data.0.created_by', $this->reporter->id)
            ->json('data.0.url');

        $attachment = Attachment::query()->firstOrFail();

        $this->assertSame(Storage::disk('public')->url($attachment->path), $url);
        $this->assertStringContainsString($attachment->path, (string) $url);
    }

    /**
     * Uploading is activity: a ticket that just gained a photo should not sort
     * below one nobody has touched in a week.
     */
    public function test_attaching_a_file_touches_the_ticket(): void
    {
        $ticket = $this->raise();
        $ticket->forceFill(['last_activity_at' => now()->subWeek()])->save();

        $this->post($this->url("/{$ticket->id}/attachments"), [
            'files' => [UploadedFile::fake()->image('receipt.jpg')],
        ], $this->headers())->assertCreated();

        $this->assertTrue($ticket->fresh()->last_activity_at->isAfter(now()->subMinute()));
    }

    // ---- what is refused -------------------------------------------------

    /**
     * text/html on a publicly-served origin is stored XSS. MaintenancePizza
     * validates ['file','max:10240'] and nothing else; this is the difference
     * between public files and public executable files.
     */
    public function test_an_html_upload_is_refused(): void
    {
        $this->post($this->url(), $this->payload([
            'files' => [UploadedFile::fake()->create('payload.html', 4, 'text/html')],
        ]), $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('files.0');

        $this->assertDatabaseCount('tickets', 0);
    }

    /**
     * The allowlist checks the SNIFFED type, so renaming the file does not get
     * it past the door.
     */
    public function test_renaming_an_svg_to_png_does_not_get_it_past_the_allowlist(): void
    {
        $this->post($this->url(), $this->payload([
            'files' => [UploadedFile::fake()->create('logo.png', 4, 'image/svg+xml')],
        ]), $this->headers())->assertStatus(422);
    }

    public function test_a_file_over_the_size_limit_is_refused(): void
    {
        config(['toolbox.tickets.attachments.max_kilobytes' => 100]);

        $this->post($this->url(), $this->payload([
            'files' => [UploadedFile::fake()->create('huge.pdf', 200, 'application/pdf')],
        ]), $this->headers())->assertStatus(422);
    }

    public function test_more_files_than_the_per_request_cap_are_refused(): void
    {
        config(['toolbox.tickets.attachments.max_per_request' => 3]);

        $this->post($this->url(), $this->payload([
            'files' => array_map(fn (int $i) => UploadedFile::fake()->image("photo-{$i}.jpg"), range(1, 4)),
        ]), $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('files');
    }

    /**
     * The cap applies per note as well, or it is trivially bypassed by sending
     * ten notes.
     */
    public function test_the_cap_applies_to_note_files_too(): void
    {
        config(['toolbox.tickets.attachments.max_per_request' => 2]);

        $this->post($this->url(), $this->payload([
            'notes' => [['body' => 'Lots', 'files' => array_map(
                fn (int $i) => UploadedFile::fake()->image("photo-{$i}.jpg"),
                range(1, 3),
            )]],
        ]), $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('notes.0.files');
    }

    /**
     * Nothing rejected at the door may reach the disk - the request never gets
     * as far as stage().
     */
    public function test_a_rejected_upload_writes_no_bytes(): void
    {
        $this->post($this->url(), $this->payload([
            'files' => [UploadedFile::fake()->create('payload.html', 4, 'text/html')],
        ]), $this->headers())->assertStatus(422);

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    // ---- reading it back -------------------------------------------------

    public function test_show_returns_notes_responses_and_attachments_together(): void
    {
        $ticket = $this->raise();

        $this->post($this->url("/{$ticket->id}/attachments"), [
            'files' => [UploadedFile::fake()->image('ticket.jpg')],
        ], $this->headers())->assertCreated();

        $this->post($this->url("/{$ticket->id}/notes"), [
            'body' => 'Engineer booked', 'files' => [UploadedFile::fake()->image('booking.jpg')],
        ], $this->headers())->assertCreated();

        $this->post($this->url("/{$ticket->id}/responses"), [
            'body' => 'Thanks', 'files' => [UploadedFile::fake()->image('reply.jpg')],
        ], $this->headers())->assertCreated();

        $this->getJson($this->url("/{$ticket->id}"), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.attachments.0.original_name', 'ticket.jpg')
            ->assertJsonPath('data.notes.0.attachments.0.original_name', 'booking.jpg')
            ->assertJsonPath('data.responses.0.attachments.0.original_name', 'reply.jpg')
            ->assertJsonPath('data.responses.0.body', 'Thanks');
    }

    /**
     * Notes and attachments are polymorphic, so nothing cascades - deleting a
     * ticket has to take them by hand or they sit here forever pointing at an id
     * that no longer exists.
     */
    public function test_deleting_a_ticket_takes_its_notes_responses_and_files_with_it(): void
    {
        $ticket = $this->raise();

        $this->post($this->url("/{$ticket->id}/attachments"), [
            'files' => [UploadedFile::fake()->image('ticket.jpg')],
        ], $this->headers())->assertCreated();

        $this->post($this->url("/{$ticket->id}/notes"), [
            'body' => 'Note', 'files' => [UploadedFile::fake()->image('note.jpg')],
        ], $this->headers())->assertCreated();

        $this->post($this->url("/{$ticket->id}/responses"), [
            'body' => 'Reply', 'files' => [UploadedFile::fake()->image('reply.jpg')],
        ], $this->headers())->assertCreated();

        $paths = Attachment::query()->pluck('path')->all();
        $this->assertCount(3, $paths);

        app(TicketService::class)->delete($ticket);

        // withTrashed on both: a soft-deleted row is still a row nothing can
        // reach, which is the orphan this guards against.
        $this->assertSame(0, Attachment::withTrashed()->count());
        $this->assertSame(0, Note::withTrashed()->count());
        $this->assertSame(0, TicketResponse::withTrashed()->count());

        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }
}
