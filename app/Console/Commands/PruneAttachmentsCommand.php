<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Services\AttachmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * The command MaintenancePizza's comments promise and nobody ever wrote.
 *
 * Two of its files assert `attachments:prune is the only place a file is ever
 * unlinked`, and it does not exist - so over there files are never removed at
 * all, and a rolled-back transaction leaks bytes permanently.
 *
 * Three passes, because there are three ways a file ends up unreferenced.
 */
class PruneAttachmentsCommand extends Command
{
    protected $signature = 'attachments:prune
                            {--days= : How long a deleted attachment keeps its bytes. Defaults to config.}
                            {--grace-hours= : Protect files newer than this from the orphan sweep. Defaults to config.}
                            {--dry-run : Report what would go and change nothing.}';

    protected $description = 'Unlink attachment files whose rows are gone, whose owners are gone, or that no row ever claimed';

    public function handle(AttachmentService $attachments): int
    {
        $days = (int) ($this->option('days') ?? config('toolbox.tickets.attachments.retention_days'));
        $graceHours = (int) ($this->option('grace-hours') ?? config('toolbox.tickets.attachments.grace_hours'));
        $dryRun = (bool) $this->option('dry-run');

        if ($days < 0 || $graceHours < 0) {
            $this->error('--days and --grace-hours cannot be negative.');

            return self::FAILURE;
        }

        $horizon = now()->subDays($days);
        $disk = $attachments->disk();

        // Pass 1: soft-deleted rows whose retention has run out.
        $expired = Attachment::onlyTrashed()->where('deleted_at', '<', $horizon);

        // Pass 2: rows whose owner has been force-deleted out from under them.
        // Morphs have no foreign key, so nothing cascades and these would
        // otherwise sit here forever.
        $orphanedRows = $this->orphanedByOwner();

        $expiredCount = (clone $expired)->count();
        $orphanRowCount = $orphanedRows->count();

        // Pass 3: files on disk that no row - not even a trashed one - claims.
        $orphanFiles = $this->orphanFiles($disk, $graceHours);

        $this->info("Retention: {$days} days, grace {$graceHours}h, disk '{$disk}'.");
        $this->info("  expired attachments:  {$expiredCount}");
        $this->info('  orphaned by owner:    '.$orphanRowCount);
        $this->info('  files with no row:    '.count($orphanFiles));

        if ($dryRun) {
            $this->warn('Dry run - nothing was deleted.');

            return self::SUCCESS;
        }

        $removed = 0;

        (clone $expired)->chunkById(500, function (Collection $chunk) use ($attachments, &$removed) {
            $removed += $attachments->purge($chunk);
        });

        $removed += $attachments->purge($orphanedRows);

        foreach ($orphanFiles as $path) {
            Storage::disk($disk)->delete($path);
        }

        $this->info("Deleted {$removed} attachment(s) and ".count($orphanFiles).' stray file(s).');

        return self::SUCCESS;
    }

    /**
     * Attachments whose owning record no longer exists.
     *
     * @return Collection<int, Attachment>
     */
    private function orphanedByOwner(): Collection
    {
        return Attachment::withTrashed()
            ->get()
            ->filter(function (Attachment $attachment) {
                // A morph can point at a class that has since been removed, so
                // resolving it is itself allowed to fail.
                try {
                    return $attachment->attachable()->withTrashed()->first() === null;
                } catch (\BadMethodCallException) {
                    // The owner model has no soft deletes.
                    return $attachment->attachable === null;
                } catch (\Throwable) {
                    return false;
                }
            })
            ->values();
    }

    /**
     * Files on disk that no row claims.
     *
     * Anything modified inside the grace window is SKIPPED: that is a staged
     * upload for a request still in flight, and deleting it would break a live
     * write. This pass is what covers a process killed between stage() and
     * commit(), where the controller's catch never ran.
     *
     * @return array<int, string>
     */
    private function orphanFiles(string $disk, int $graceHours): array
    {
        $directory = 'ticket-attachments';

        if (! Storage::disk($disk)->exists($directory)) {
            return [];
        }

        $known = Attachment::withTrashed()->where('disk', $disk)->pluck('path')->flip();
        $cutoff = now()->subHours($graceHours)->getTimestamp();

        return collect(Storage::disk($disk)->allFiles($directory))
            ->reject(fn (string $path) => $known->has($path))
            // Strictly AFTER the cutoff, not on it: mtimes have one-second
            // resolution, so `>=` would make --grace-hours=0 still protect every
            // file written in the current second - which is the opposite of what
            // asking for no grace means.
            ->reject(fn (string $path) => Storage::disk($disk)->lastModified($path) > $cutoff)
            ->values()
            ->all();
    }
}
