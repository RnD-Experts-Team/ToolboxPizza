<?php

namespace Tests\Feature\Tickets;

use App\Models\Attachment;
use App\Models\Note;
use App\Models\Ticket;
use App\Models\TicketResponse;
use App\Models\ToolboxOutboxEvent;
use App\Models\User;
use App\Services\ToolboxEvents\ToolboxOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\BuildsTicketWorld;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * What a failure leaves behind.
 *
 * MaintenancePizza writes bytes INSIDE the domain transaction, so a rollback
 * discards the rows and leaves the files on disk with nothing pointing at them,
 * forever, with no prune command to find them. The three-step shape here -
 * stage() outside, commit() inside, discard() from the catch - exists for this
 * test, so the test has to actually fail a transaction rather than assert the
 * happy path twice.
 *
 * The failure is injected at the notifier, which runs INSIDE the transaction and
 * AFTER commit() - the worst moment, where rows and bytes both already exist.
 */
class TicketTransactionRollbackTest extends TestCase
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
            'toolbox.tickets.notifications.enabled' => true,
            'toolbox.tickets.realtime.enabled' => false,
        ]);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/stores/{$this->store->store_number}/tickets{$suffix}";
    }

    /**
     * Blow up inside the transaction, after the attachment rows are already in.
     *
     * The notification is the last thing written inside every ticket write, so
     * making the outbox throw on it fails the transaction at exactly that point.
     * An anonymous subclass rather than a mock keeps the failure obvious from
     * the test body.
     */
    private function breakTheNotifier(): void
    {
        config(['toolbox.tickets.notifications.enabled' => true]);

        // Somebody has to be listening, or there is nothing to notify and the
        // injected failure is never reached.
        $this->makeAssignee(50);

        $this->instance(ToolboxOutboxService::class, new class extends ToolboxOutboxService
        {
            public function __construct() {}

            public function notify(array $userIds, array $notification): ToolboxOutboxEvent
            {
                throw new RuntimeException('Injected failure inside the transaction.');
            }
        });
    }

    public function test_a_failure_after_the_upload_leaves_no_row_and_no_bytes(): void
    {
        $this->breakTheNotifier();

        $this->post($this->url(), [
            'section_key' => $this->section->key,
            'title' => 'Never lands',
            'description' => 'The notifier will throw.',
            'files' => [UploadedFile::fake()->image('doomed.jpg')],
        ], $this->headers())->assertStatus(500);

        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(0, Attachment::withTrashed()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /**
     * Per-note files are staged separately and have to be discarded too - one
     * forgotten array is a permanent leak.
     */
    public function test_note_files_are_discarded_alongside_ticket_files(): void
    {
        $this->breakTheNotifier();

        $this->post($this->url(), [
            'section_key' => $this->section->key,
            'title' => 'Never lands',
            'description' => 'With files at two depths.',
            'files' => [UploadedFile::fake()->image('top.jpg')],
            'notes' => [
                ['body' => 'One', 'files' => [UploadedFile::fake()->image('a.jpg')]],
                ['body' => 'Two', 'files' => [UploadedFile::fake()->image('b.jpg')]],
            ],
        ], $this->headers())->assertStatus(500);

        $this->assertSame(0, Note::withTrashed()->count());
        $this->assertSame(0, Attachment::withTrashed()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /**
     * The notification is recorded inside the same transaction, so a ticket that
     * rolled back can never have told anybody it existed.
     */
    public function test_a_rolled_back_ticket_publishes_nothing(): void
    {
        $this->breakTheNotifier();

        $this->post($this->url(), [
            'section_key' => $this->section->key,
            'title' => 'Never lands',
            'description' => 'Silent failure.',
        ], $this->headers())->assertStatus(500);

        $this->assertDatabaseCount('toolbox_outbox_events', 0);
        $this->assertDatabaseCount('ticket_status_changes', 0);
    }

    /**
     * The same guarantee on the reply path, which stages and commits through a
     * different service.
     */
    public function test_a_failed_reply_leaves_the_ticket_untouched(): void
    {
        $ticket = Ticket::factory()->create([
            'store_id' => $this->store->id,
            'ticket_section_id' => $this->section->id,
            'reported_by' => $this->reporter->id,
            'last_activity_at' => now()->subWeek(),
        ]);

        $before = $ticket->last_activity_at;

        $this->breakTheNotifier();

        $this->post($this->url("/{$ticket->id}/responses"), [
            'body' => 'Never lands',
            'files' => [UploadedFile::fake()->image('doomed.jpg')],
        ], $this->headers())->assertStatus(500);

        $this->assertSame(0, TicketResponse::withTrashed()->count());
        $this->assertSame(0, Attachment::withTrashed()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());

        // first_responded_at and last_activity_at are both written inside that
        // transaction, so neither may have moved.
        $ticket->refresh();
        $this->assertNull($ticket->first_responded_at);
        $this->assertTrue($before->equalTo($ticket->last_activity_at));
    }

    /**
     * The other half of the contract: when nothing fails, the bytes stay. A
     * discard() that fired on the happy path would be far worse than one that
     * never fired at all.
     */
    public function test_a_successful_request_keeps_its_bytes(): void
    {
        $this->post($this->url(), [
            'section_key' => $this->section->key,
            'title' => 'This one lands',
            'description' => 'Keep the file.',
            'files' => [UploadedFile::fake()->image('kept.jpg')],
        ], $this->headers())->assertCreated();

        $this->assertSame(1, Attachment::query()->count());

        Storage::disk('public')->assertExists(Attachment::query()->value('path'));
    }
}
