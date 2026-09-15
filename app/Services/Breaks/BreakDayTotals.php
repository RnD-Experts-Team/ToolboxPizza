<?php

namespace App\Services\Breaks;

use App\Models\BreakEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The counted/excluded arithmetic for one work day, in one place.
 *
 * Shared by BreakDayReportService (which wraps it in the full day document) and
 * BreakBroadcaster (which puts it on the wire so a client can redraw without a
 * follow-up fetch). Extracted rather than duplicated: two implementations of
 * "how much break has this person had" would drift, and the broadcast figure
 * disagreeing with the summary figure is exactly the bug nobody would spot.
 *
 * It also breaks what would otherwise be a dependency cycle - the evaluator
 * needs the broadcaster, and the report needs the evaluator.
 *
 * ROUNDING: arithmetic in seconds, displayed minutes are intdiv($seconds, 60).
 */
class BreakDayTotals
{
    public function __construct(private readonly BreakSettingsService $settings) {}

    /**
     * @param  Collection<int, BreakEntry>|null  $entries  pre-loaded entries, when the caller already has them
     * @return array<string, mixed>
     */
    public function forDay(User $user, string $workDate, ?CarbonInterface $asOf = null, ?Collection $entries = null): array
    {
        $asOf = $asOf === null ? CarbonImmutable::now() : CarbonImmutable::parse($asOf);
        $entries ??= $this->entries($user, $workDate);

        $countedSeconds = 0;
        $excludedSeconds = 0;
        $hasActive = false;

        foreach ($entries as $entry) {
            $seconds = $entry->elapsedSeconds($asOf);

            if ($entry->counts_toward_limit) {
                $countedSeconds += $seconds;
            } else {
                $excludedSeconds += $seconds;
            }

            $hasActive = $hasActive || $entry->isRunning();
        }

        $allowanceMinutes = $this->settings->forUser($user)->daily_allowance_minutes;
        $countedMinutes = intdiv($countedSeconds, 60);

        return [
            'allowance_minutes' => $allowanceMinutes,
            'counted_seconds' => $countedSeconds,
            'counted_minutes' => $countedMinutes,
            'excluded_seconds' => $excludedSeconds,
            'excluded_minutes' => intdiv($excludedSeconds, 60),
            'total_seconds' => $countedSeconds + $excludedSeconds,
            'total_minutes' => intdiv($countedSeconds + $excludedSeconds, 60),
            'remaining_minutes' => max(0, $allowanceMinutes - $countedMinutes),
            'over_minutes' => max(0, $countedMinutes - $allowanceMinutes),
            'over_limit' => $countedMinutes > $allowanceMinutes,
            'entry_count' => $entries->count(),
            'has_active_break' => $hasActive,
        ];
    }

    /**
     * @return Collection<int, BreakEntry>
     */
    public function entries(User $user, string $workDate): Collection
    {
        return BreakEntry::query()
            ->with(['breakType', 'notes.creator'])
            ->where('user_id', $user->id)
            ->forWorkDate($workDate)
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();
    }
}
