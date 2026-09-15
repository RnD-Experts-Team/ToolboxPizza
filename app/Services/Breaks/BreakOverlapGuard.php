<?php

namespace App\Services\Breaks;

use App\Models\BreakEntry;
use App\Services\Breaks\Exceptions\BreakException;
use Carbon\CarbonImmutable;

/**
 * Stops a user's breaks from overlapping each other.
 *
 * Works in ABSOLUTE INSTANTS and never filters by work_date. That is
 * deliberate and load-bearing: a break that runs 23:00 to 07:00 belongs to
 * yesterday's work day but must still block an 02:00 entry that belongs to
 * today's. Scoping this query by day would let those two coexist.
 *
 * There is no artificial time window either. 30-day retention already bounds
 * how many rows a user has, and a window would let a forgotten 40-hour break
 * slip out of the check entirely.
 */
class BreakOverlapGuard
{
    /**
     * A running break has no end yet, so it is treated as extending forever -
     * far enough that no real entry can be placed after it while it is open.
     */
    private const OPEN_ENDED = '9999-12-31 23:59:59';

    /**
     * @throws BreakException
     */
    public function assertNoOverlap(
        int $userId,
        CarbonImmutable $startsAt,
        ?CarbonImmutable $endsAt,
        ?int $ignoreId = null,
    ): void {
        $conflicts = $this->conflicts($userId, $startsAt, $endsAt, $ignoreId);

        if ($conflicts !== []) {
            throw BreakException::overlap($conflicts);
        }
    }

    /**
     * Half-open intervals: two breaks that touch end-to-start do NOT overlap,
     * so ending one at 14:13 and starting the next at 14:13 is allowed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function conflicts(
        int $userId,
        CarbonImmutable $startsAt,
        ?CarbonImmutable $endsAt,
        ?int $ignoreId = null,
    ): array {
        $newEnd = $endsAt?->toDateTimeString() ?? self::OPEN_ENDED;

        return BreakEntry::query()
            ->with('breakType')
            ->where('user_id', $userId)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->where('started_at', '<', $newEnd)
            ->where(function ($q) use ($startsAt) {
                $q->whereNull('ended_at')
                    ->orWhere('ended_at', '>', $startsAt->toDateTimeString());
            })
            ->orderBy('started_at')
            ->get()
            ->map(fn (BreakEntry $entry) => [
                'id' => $entry->id,
                'label' => $entry->label(),
                'started_at' => $entry->started_at->toIso8601String(),
                'ended_at' => $entry->ended_at?->toIso8601String(),
                'running' => $entry->isRunning(),
                'work_date' => $entry->work_date->toDateString(),
            ])
            ->all();
    }
}
