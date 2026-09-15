<?php

namespace App\Console\Commands;

use App\Models\BreakEntry;
use App\Models\User;
use App\Services\Breaks\BreakMilestoneEvaluator;
use App\Services\Breaks\WorkDayResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Notice milestone crossings for breaks that are currently running, without
 * waiting for anyone to ask.
 *
 * SHIPPED BUT NOT SCHEDULED. A stateless API has no in-process timer, so
 * crossings are normally detected on the write paths and on reads of the
 * current work day - which is enough while notifications are off, because the
 * only consumer of a crossing is the response the client is already reading.
 *
 * Schedule this at the same time as enabling TOOLBOX_NOTIFICATIONS_ENABLED:
 * once a milestone becomes a push, "we noticed when you next asked" is not good
 * enough, and a user who closed the tab is never told at all. See
 * routes/console.php for the entry to uncomment.
 */
class EvaluateBreakMilestonesCommand extends Command
{
    protected $signature = 'breaks:evaluate-milestones
                            {--dry-run : Report who would be evaluated and change nothing.}';

    protected $description = 'Evaluate milestone thresholds for every user with a break currently running';

    public function handle(BreakMilestoneEvaluator $milestones, WorkDayResolver $workDays): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Only users with an open break can newly cross a threshold: a finished
        // break was already evaluated when it was written. Indexed on
        // (user_id, ended_at).
        $userIds = BreakEntry::query()->running()->distinct()->pluck('user_id');

        if ($userIds->isEmpty()) {
            $this->info('No breaks are running.');

            return self::SUCCESS;
        }

        $this->info("{$userIds->count()} user(s) on a break.");

        if ($dryRun) {
            $this->warn('Dry run - nothing was evaluated.');

            return self::SUCCESS;
        }

        $fired = 0;

        User::query()->whereIn('id', $userIds)->chunkById(200, function (Collection $users) use ($milestones, $workDays, &$fired) {
            foreach ($users as $user) {
                // The CURRENT work day: a break that started before the cutoff
                // keeps its own day, so evaluate the day the break belongs to.
                $running = BreakEntry::query()->where('user_id', $user->id)->running()->first();

                $workDate = $running?->work_date->toDateString() ?? $workDays->currentWorkDate();

                $fired += $milestones->evaluate($user, $workDate)->count();
            }
        });

        $this->info("Fired {$fired} milestone(s).");

        return self::SUCCESS;
    }
}
