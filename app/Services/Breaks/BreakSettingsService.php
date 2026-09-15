<?php

namespace App\Services\Breaks;

use App\Models\BreakMilestone;
use App\Models\User;
use App\Models\UserBreakSetting;
use Illuminate\Support\Facades\DB;

/**
 * A user's own allowance and milestone thresholds. There is no manager or admin
 * in this service: the person taking the breaks sets the budget they are
 * measured against, and the system reports rather than polices.
 */
class BreakSettingsService
{
    /**
     * The user's settings row, created on first touch from the configured
     * default. Every other break path goes through here rather than querying
     * the table, because the row must EXIST before it can be locked.
     */
    public function forUser(User $user): UserBreakSetting
    {
        return UserBreakSetting::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['daily_allowance_minutes' => (int) config('toolbox.breaks.default_daily_allowance_minutes')],
        );
    }

    /**
     * The same row, locked for the rest of the transaction.
     *
     * This is how "one open break at a time" is enforced: MySQL has no partial
     * unique index that could express "at most one NULL ended_at per user", so
     * writers serialise on this row first and then look for an open break.
     * firstOrCreate runs before the lock because you cannot lock a row that does
     * not exist yet.
     */
    public function lockForUser(User $user): UserBreakSetting
    {
        $this->forUser($user);

        return UserBreakSetting::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function updateAllowance(User $user, int $minutes): UserBreakSetting
    {
        $settings = $this->forUser($user);
        $settings->update(['daily_allowance_minutes' => $minutes]);

        return $settings;
    }

    /**
     * The user's thresholds in minutes, ascending.
     *
     * @return array<int, int>
     */
    public function thresholds(User $user): array
    {
        return BreakMilestone::query()
            ->where('user_id', $user->id)
            ->orderBy('threshold_minutes')
            ->pluck('threshold_minutes')
            ->map(fn (int $m) => $m)
            ->all();
    }

    /**
     * Replace the whole set. Whole-list replacement rather than per-row edits
     * because that is how the user thinks about it — "these are my milestones" —
     * and it keeps the client from having to diff.
     *
     * Deduplicated and sorted on the way in. A threshold above the allowance is
     * allowed: with a soft limit, "tell me at 70 minutes" is a meaningful thing
     * to ask for even when the budget is 50.
     *
     * @param  array<int, int>  $minutes
     * @return array<int, int>
     */
    public function replaceThresholds(User $user, array $minutes): array
    {
        $wanted = collect($minutes)
            ->map(fn ($m) => (int) $m)
            ->filter(fn (int $m) => $m > 0)
            ->unique()
            ->sort()
            ->values();

        DB::transaction(function () use ($user, $wanted) {
            BreakMilestone::query()
                ->where('user_id', $user->id)
                ->whereNotIn('threshold_minutes', $wanted->all())
                ->delete();

            foreach ($wanted as $threshold) {
                // firstOrCreate, not insert: keeping the existing row for an
                // unchanged threshold means its id and timestamps survive a
                // "replace" that did not actually change it.
                BreakMilestone::query()->firstOrCreate([
                    'user_id' => $user->id,
                    'threshold_minutes' => $threshold,
                ]);
            }
        });

        return $wanted->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(User $user): array
    {
        $settings = $this->forUser($user);

        return [
            'daily_allowance_minutes' => $settings->daily_allowance_minutes,
            'thresholds' => $this->thresholds($user),
            'work_day' => [
                'cutoff_hour' => (int) config('toolbox.work_day.cutoff_hour'),
                'timezone' => (string) config('toolbox.work_day.timezone'),
            ],
            'max_milestones' => (int) config('toolbox.breaks.max_milestones'),
        ];
    }
}
