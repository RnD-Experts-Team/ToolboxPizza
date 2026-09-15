<?php

namespace App\Console\Commands;

use App\Models\BreakEntry;
use App\Models\BreakMilestoneFiring;
use App\Models\Note;
use App\Services\Breaks\WorkDayResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Break data is kept for a limited window and then deleted outright.
 *
 * Once a day is pruned it cannot be re-exported, which is the whole point:
 * this is a self-service timer, not a personnel record. Anyone who needs an
 * archive has to capture the export before the horizon passes.
 */
class PruneBreaksCommand extends Command
{
    protected $signature = 'breaks:prune
                            {--days= : Retention window in days. Defaults to config(toolbox.breaks.retention_days).}
                            {--dry-run : Report what would be deleted and change nothing.}';

    protected $description = 'Delete break entries, their notes and their milestone firings past the retention window';

    public function handle(WorkDayResolver $workDays): int
    {
        $days = (int) ($this->option('days') ?? config('toolbox.breaks.retention_days'));
        $dryRun = (bool) $this->option('dry-run');

        if ($days < 0) {
            $this->error('--days cannot be negative.');

            return self::FAILURE;
        }

        // Everything strictly BEFORE this work date goes. Scheduled just after
        // the cutoff, so a work day is never pruned while it is still running.
        $horizon = $workDays->oldestRetainedWorkDate($days);

        $entries = BreakEntry::query()->where('work_date', '<', $horizon);
        $firings = BreakMilestoneFiring::query()->where('work_date', '<', $horizon);

        $entryCount = (clone $entries)->count();
        $firingCount = (clone $firings)->count();
        $noteCount = 0;
        $deleted = 0;

        (clone $entries)->select('id')->chunkById(500, function (Collection $chunk) use (&$noteCount) {
            $noteCount += Note::query()
                ->where('notable_type', (new BreakEntry)->getMorphClass())
                ->whereIn('notable_id', $chunk->pluck('id'))
                ->withTrashed()
                ->count();
        });

        $this->info("Retention: {$days} days. Pruning work dates before {$horizon}.");
        $this->info("  break entries:     {$entryCount}");
        $this->info("  notes:             {$noteCount}");
        $this->info("  milestone firings: {$firingCount}");

        if ($dryRun) {
            $this->warn('Dry run - nothing was deleted.');

            return self::SUCCESS;
        }

        (clone $entries)->select('id')->chunkById(500, function (Collection $chunk) use (&$deleted) {
            $ids = $chunk->pluck('id');

            // Notes are polymorphic, so there is no foreign key and NOTHING
            // cascades - deleting them here is not optional. forceDelete
            // because 30-day retention means gone, not hidden.
            Note::query()
                ->where('notable_type', (new BreakEntry)->getMorphClass())
                ->whereIn('notable_id', $ids)
                ->withTrashed()
                ->forceDelete();

            $deleted += BreakEntry::query()->whereIn('id', $ids)->delete();
        });

        $firings->delete();

        $this->info("Deleted {$deleted} break entries, {$noteCount} notes and {$firingCount} milestone firings.");

        return self::SUCCESS;
    }
}
