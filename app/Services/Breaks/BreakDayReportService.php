<?php

namespace App\Services\Breaks;

use App\Models\BreakEntry;
use App\Models\BreakMilestoneFiring;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The day breakdown: where a user's break time went, and the document they send
 * their manager.
 *
 * ROUNDING RULE, applied everywhere: arithmetic in seconds, displayed minutes
 * are intdiv($seconds, 60). A consequence to design around rather than "fix":
 * the per-entry minute column need not sum to the day total, because the floor
 * of a sum is not the sum of floors. The renderer therefore prints the day
 * total from the day figure and never by adding its own column.
 */
class BreakDayReportService
{
    public function __construct(
        private readonly WorkDayResolver $workDays,
        private readonly BreakSettingsService $settings,
        private readonly BreakQueryService $reader,
        private readonly BreakMilestoneEvaluator $milestones,
        private readonly BreakTextRenderer $renderer,
        private readonly BreakDayTotals $totals,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forDay(User $user, ?string $workDate = null, ?CarbonInterface $asOf = null): array
    {
        $asOf = $asOf === null ? CarbonImmutable::now() : CarbonImmutable::parse($asOf);
        $workDate ??= $this->workDays->currentWorkDate($asOf);

        [$windowStart, $windowEnd] = $this->workDays->windowFor($workDate);

        $entries = $this->totals->entries($user, $workDate);

        // One implementation of the counted/excluded arithmetic, shared with
        // BreakBroadcaster - a broadcast figure that disagreed with the summary
        // figure is exactly the bug nobody would spot.
        $totals = $this->totals->forDay($user, $workDate, $asOf, $entries);

        return [
            'work_date' => $workDate,
            'work_day' => [
                'starts_at' => $windowStart->toIso8601String(),
                'ends_at' => $windowEnd->toIso8601String(),
                'cutoff_hour' => $this->workDays->cutoffHour(),
                'timezone' => $this->workDays->timezone(),
            ],
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],

            ...$totals,

            'as_of' => $asOf->toIso8601String(),
            'generated_at' => $asOf->toIso8601String(),

            // This document is what the USER says happened. Breaks can be
            // edited in place with no revision history, by design, so the
            // export is self-reported rather than evidence - and says so.
            'self_reported' => true,

            'categories' => $this->categories($entries, $asOf),
            'entries' => $entries->map(fn (BreakEntry $entry) => $this->reader->present($entry, $asOf))->all(),
            'milestones' => $this->milestoneBlock($user, $workDate, $totals['counted_minutes']),
        ];
    }

    /**
     * The day payload plus the ready-to-paste rendering.
     *
     * A separate endpoint from forDay() because the day view is the one a
     * client polls, and it should not render a document on every tick.
     *
     * @return array<string, mixed>
     */
    public function export(User $user, ?string $workDate = null, ?CarbonInterface $asOf = null): array
    {
        $summary = $this->forDay($user, $workDate, $asOf);
        $summary['text'] = $this->renderer->render($summary);

        return $summary;
    }

    /**
     * Where the day's break time went, by type, biggest first.
     *
     * @param  Collection<int, BreakEntry>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function categories(Collection $entries, CarbonImmutable $asOf): array
    {
        $categories = [];

        foreach ($entries as $entry) {
            // Keyed by type, except "Custom / Other", which is grouped by its
            // free text - two different custom breaks are two categories, which
            // is the whole point of letting the user name them.
            $key = $entry->other_label !== null
                ? 'other:'.$entry->other_label
                : 'type:'.$entry->break_type_id;

            $categories[$key] ??= [
                'break_type_id' => $entry->break_type_id,
                'slug' => $entry->breakType?->slug,
                'name' => $entry->breakType?->name,
                'label' => $entry->label(),
                // The snapshot on the entry, not a live catalog join.
                'counts_toward_limit' => $entry->counts_toward_limit,
                'entry_count' => 0,
                'seconds' => 0,
                'minutes' => 0,
            ];

            $categories[$key]['entry_count']++;
            $categories[$key]['seconds'] += $entry->elapsedSeconds($asOf);
        }

        foreach ($categories as $key => $category) {
            $categories[$key]['minutes'] = intdiv($category['seconds'], 60);
        }

        $categories = array_values($categories);

        usort($categories, function (array $a, array $b) {
            // Counted first, then longest first - the order someone reads them.
            return [$b['counts_toward_limit'], $b['seconds']] <=> [$a['counts_toward_limit'], $a['seconds']];
        });

        return $categories;
    }

    /**
     * @return array<string, mixed>
     */
    private function milestoneBlock(User $user, string $workDate, int $countedMinutes): array
    {
        $thresholds = $this->settings->thresholds($user);

        $fired = $this->milestones->firings($user, $workDate)
            ->map(fn (BreakMilestoneFiring $firing) => [
                'kind' => $firing->kind->value,
                'threshold_minutes' => $firing->threshold_minutes,
                // The true instant, which may be well before anyone looked.
                'crossed_at' => $firing->crossed_at->toIso8601String(),
                'noticed_at' => $firing->noticed_at->toIso8601String(),
                'notified' => $firing->wasEmitted(),
            ])
            ->all();

        return [
            'thresholds' => $thresholds,
            'fired' => $fired,
            'pending' => array_values(array_filter(
                $thresholds,
                fn (int $minutes) => $minutes > $countedMinutes,
            )),
        ];
    }
}
