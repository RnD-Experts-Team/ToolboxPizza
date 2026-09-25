<?php

namespace Tests\Feature\Attachments;

use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTicketWorld;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * attachments:prune, which MaintenancePizza's comments promise and nobody wrote.
 *
 * Two of its files assert "attachments:prune is the only place a file is ever
 * unlinked", and the command does not exist over there - so files are never
 * removed at all. Three passes here, because there are three separate ways a
 * file ends up unreferenced, and each needs its own proof.
 */
class AttachmentPruneTest extends TestCase
{
    use BuildsTicketWorld, FakesAuthServer, RefreshDatabase;

    private User $reporter;

    private Ticket $ticket;

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
            'toolbox.tickets.attachments.retention_days' => 30,
            'toolbox.tickets.attachments.grace_hours' => 24,
        ]);

        $this->ticket = Ticket::factory()->create([
            'store_id' => $this->store->id,
            'ticket_section_id' => $this->section->id,
            'reported_by' => $this->reporter->id,
        ]);
    }

    private function attach(string $name = 'photo.jpg'): Attachment
    {
        $this->post(
            "/api/v1/stores/{$this->store->store_number}/tickets/{$this->ticket->id}/attachments",
            ['files' => [UploadedFile::fake()->image($name)]],
            $this->headers(),
        )->assertCreated();

        return Attachment::query()->where('original_name', $name)->latest('id')->firstOrFail();
    }

    // ---- pass 1: retention -----------------------------------------------

    public function test_a_soft_deleted_attachment_past_retention_loses_its_row_and_its_bytes(): void
    {
        $attachment = $this->attach();
        $attachment->delete();
        $attachment->forceFill(['deleted_at' => now()->subDays(31)])->saveQuietly();

        $this->artisan('attachments:prune')->assertSuccessful();

        $this->assertSame(0, Attachment::withTrashed()->count());
        Storage::disk('public')->assertMissing($attachment->path);
    }

    /**
     * Retention is a window, not a trigger: something deleted yesterday is still
     * recoverable, and its bytes have to be there for that to mean anything.
     */
    public function test_a_recently_deleted_attachment_keeps_its_bytes(): void
    {
        $attachment = $this->attach();
        $attachment->delete();

        $this->artisan('attachments:prune')->assertSuccessful();

        $this->assertSame(1, Attachment::withTrashed()->count());
        Storage::disk('public')->assertExists($attachment->path);
    }

    public function test_a_live_attachment_is_never_touched(): void
    {
        $attachment = $this->attach();

        $this->artisan('attachments:prune --days=0 --grace-hours=0')->assertSuccessful();

        $this->assertSame(1, Attachment::query()->count());
        Storage::disk('public')->assertExists($attachment->path);
    }

    // ---- pass 2: the owner is gone ---------------------------------------

    /**
     * Morphs have no foreign key, so nothing cascades. An attachment whose ticket
     * was force-deleted out from under it would otherwise sit here forever
     * pointing at an id that no longer exists.
     */
    public function test_an_attachment_whose_owner_vanished_is_removed(): void
    {
        $attachment = $this->attach();

        // Straight to the table, bypassing TicketService::delete() - that
        // is exactly the situation this pass exists for.
        Ticket::query()->whereKey($this->ticket->id)->forceDelete();

        $this->artisan('attachments:prune')->assertSuccessful();

        $this->assertSame(0, Attachment::withTrashed()->count());
        Storage::disk('public')->assertMissing($attachment->path);
    }

    /**
     * A SOFT-deleted owner is not a vanished owner - the ticket can come back,
     * and its files have to come back with it.
     */
    public function test_a_soft_deleted_owner_does_not_orphan_its_attachments(): void
    {
        $attachment = $this->attach();

        $this->ticket->delete();

        $this->artisan('attachments:prune')->assertSuccessful();

        $this->assertSame(1, Attachment::query()->count());
        Storage::disk('public')->assertExists($attachment->path);
    }

    // ---- pass 3: files nothing claims ------------------------------------

    /**
     * THE GRACE WINDOW, and the reason it is not optional.
     *
     * A file with no row is usually rubbish - but it is also exactly what a
     * request still in flight looks like between stage() and commit(). Deleting
     * it would break a live write, so anything modified inside the window is
     * skipped.
     */
    public function test_a_freshly_staged_file_with_no_row_is_protected_by_the_grace_window(): void
    {
        Storage::disk('public')->put('ticket-attachments/2026/09/in-flight.jpg', 'bytes');

        $this->artisan('attachments:prune')->assertSuccessful();

        Storage::disk('public')->assertExists('ticket-attachments/2026/09/in-flight.jpg');
    }

    /**
     * Past the window, the same file is rubbish: this is what covers a process
     * killed between stage() and commit(), where the controller's catch never
     * ran at all.
     */
    public function test_a_file_no_row_claims_is_removed_once_the_window_has_passed(): void
    {
        Storage::disk('public')->put('ticket-attachments/2026/09/abandoned.jpg', 'bytes');

        $this->artisan('attachments:prune --grace-hours=0')->assertSuccessful();

        Storage::disk('public')->assertMissing('ticket-attachments/2026/09/abandoned.jpg');
    }

    /**
     * A trashed row still CLAIMS its file - pass 1 owns that decision, and pass 3
     * must not reach past it and delete the bytes early.
     */
    public function test_a_file_claimed_by_a_trashed_row_survives_the_orphan_sweep(): void
    {
        $attachment = $this->attach();
        $attachment->delete();

        $this->artisan('attachments:prune --days=365 --grace-hours=0')->assertSuccessful();

        Storage::disk('public')->assertExists($attachment->path);
    }

    // ---- the switches -----------------------------------------------------

    public function test_a_dry_run_reports_and_changes_nothing(): void
    {
        $attachment = $this->attach();
        $attachment->delete();
        $attachment->forceFill(['deleted_at' => now()->subDays(31)])->saveQuietly();

        Storage::disk('public')->put('ticket-attachments/2026/09/abandoned.jpg', 'bytes');

        $this->artisan('attachments:prune --grace-hours=0 --dry-run')
            ->expectsOutputToContain('expired attachments:  1')
            ->expectsOutputToContain('files with no row:    1')
            ->assertSuccessful();

        $this->assertSame(1, Attachment::withTrashed()->count());
        Storage::disk('public')->assertExists($attachment->path);
        Storage::disk('public')->assertExists('ticket-attachments/2026/09/abandoned.jpg');
    }

    /**
     * A negative retention would compute a horizon in the future and delete
     * everything, so it is refused rather than interpreted.
     */
    public function test_negative_options_are_refused(): void
    {
        $this->artisan('attachments:prune --days=-1')->assertFailed();
        $this->artisan('attachments:prune --grace-hours=-1')->assertFailed();
    }

    public function test_running_it_against_an_empty_disk_is_harmless(): void
    {
        $this->artisan('attachments:prune')->assertSuccessful();

        $this->assertSame(0, Attachment::withTrashed()->count());
    }
}
