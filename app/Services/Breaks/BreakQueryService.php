<?php

namespace App\Services\Breaks;

use App\Models\BreakEntry;
use App\Models\BreakMilestone;
use App\Models\BreakMilestoneFiring;
use App\Models\BreakType;
use App\Models\Note;
use App\Models\User;
use App\Models\UserBreakSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Every read in the breaks module, and how it is presented.
 *
 * ROUNDING RULE, applied everywhere: arithmetic in seconds, displayed minutes
 * are intdiv($seconds, 60). A consequence to design around rather than "fix":
 * the per-entry minute column need not sum to the day total, because the floor
 * of a sum is not the sum of floors. Day totals therefore always come from the
 * day figures, never from adding up entries.
 */
class BreakQueryService
{
    public function __construct(private readonly WorkDayResolver $workDays) {}

    // -------------------------------------------------------------------------
    // Entries
    // -------------------------------------------------------------------------

    /**
     * 404, never 403: a 403 would confirm that someone else's break id exists,
     * which is enough to probe the table.
     */
    public function findOwn(User $user, int $breakId): BreakEntry
    {
        return BreakEntry::query()
            ->with(['breakType', 'notes.creator'])
            ->where('user_id', $user->id)
            ->findOrFail($breakId);
    }

    /**
     * `from`/`to` are WORK dates, not calendar dates - a break that ran past
     * midnight is filed under the day it started.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function index(User $user, array $filters = []): LengthAwarePaginator
    {
        $asOf = CarbonImmutable::now();
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 200);

        return BreakEntry::query()
            ->with(['breakType', 'notes.creator'])
            ->where('user_id', $user->id)
            ->when(filled($filters['from'] ?? null), fn ($q) => $q->where('work_date', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($q) => $q->where('work_date', '<=', $filters['to']))
            ->when(filled($filters['source'] ?? null), fn ($q) => $q->where('source', $filters['source']))
            ->when(
                ($filters['counts_toward_limit'] ?? null) !== null,
                fn ($q) => $q->where('counts_toward_limit', (bool) $filters['counts_toward_limit']),
            )
            ->when(
                filled($filters['break_type_ids'] ?? null),
                fn ($q) => $q->whereIn('break_type_id', (array) $filters['break_type_ids']),
            )
            // Newest first, then by id so the order is total even when two
            // entries share a start instant.
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (BreakEntry $entry) => $this->present($entry, $asOf));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function active(User $user, ?CarbonInterface $asOf = null): ?array
    {
        $asOf = $this->asOf($asOf);

        $running = BreakEntry::query()
            ->with(['breakType', 'notes.creator'])
            ->where('user_id', $user->id)
            ->running()
            ->orderByDesc('started_at')
            ->first();

        if ($running === null) {
            return null;
        }

        $presented = $this->present($running, $asOf);

        // A break that was still running when the cutoff passed keeps its start
        // day, so the client can say "this belongs to yesterday" instead of
        // silently disagreeing with today's summary, which excludes it.
        $presented['belongs_to_previous_work_day'] =
            $running->work_date->toDateString() !== $this->workDays->currentWorkDate($asOf);

        return $presented;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(BreakEntry $entry, ?CarbonInterface $asOf = null): array
    {
        $entry->loadMissing(['breakType', 'notes.creator']);
        $elapsed = $entry->elapsedSeconds($asOf);

        return [
            'id' => $entry->id,
            'break_type' => [
                'id' => $entry->breakType?->id,
                'slug' => $entry->breakType?->slug,
                'name' => $entry->breakType?->name,
                'group' => $entry->breakType?->group?->value,
                'requires_custom_label' => (bool) $entry->breakType?->requires_custom_label,
            ],
            'other_label' => $entry->other_label,
            // Resolves the catalog-or-free-text split so clients never branch.
            'label' => $entry->label(),
            'started_at' => $entry->started_at->toIso8601String(),
            'ended_at' => $entry->ended_at?->toIso8601String(),
            'running' => $entry->isRunning(),
            'work_date' => $entry->work_date->toDateString(),
            'duration_seconds' => $elapsed,
            // Floor, never round - see the class docblock.
            'duration_minutes' => intdiv($elapsed, 60),
            // The snapshot taken when the entry was written, not a live join on
            // the catalog - so a summary already sent to a manager cannot be
            // rewritten by a later reclassification.
            'counts_toward_limit' => $entry->counts_toward_limit,
            'source' => $entry->source->value,
            'notes' => $entry->notes->map(fn (Note $note) => $this->presentNote($note))->all(),
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentNote(Note $note): array
    {
        return [
            'id' => $note->id,
            'body' => $note->body,
            'created_by' => $note->created_by,
            'creator' => $note->creator === null ? null : [
                'id' => $note->creator->id,
                'name' => $note->creator->name,
            ],
            'created_at' => $note->created_at?->toIso8601String(),
        ];
    }

    // -------------------------------------------------------------------------
    // Catalogue and settings
    // -------------------------------------------------------------------------

    /**
     * The break picker, in display order.
     *
     * `group` is how the client blocks and headers them; `counts_toward_limit`
     * is the arithmetic. They agree for every seeded row, but a client must
     * read the boolean, not the group, when it explains a total.
     *
     * @return array<int, array<string, mixed>>
     */
    public function types(): array
    {
        return BreakType::query()->selectable()->get()->map(fn (BreakType $type) => [
            'id' => $type->id,
            'slug' => $type->slug,
            'name' => $type->name,
            'group' => $type->group->value,
            'group_label' => $type->group->label(),
            'counts_toward_limit' => $type->counts_toward_limit,
            'requires_custom_label' => $type->requires_custom_label,
            'sort_order' => $type->sort_order,
        ])->all();
    }

    /**
     * Everything the client needs to know about the user's budget, including
     * the work-day constants, so nothing is hardcoded client-side.
     *
     * @return array<string, mixed>
     */
    public function settings(User $user): array
    {
        return [
            'daily_allowance_minutes' => UserBreakSetting::forUser($user)->daily_allowance_minutes,
            'thresholds' => BreakMilestone::thresholdsFor($user),
            'work_day' => [
                'cutoff_hour' => (int) config('toolbox.work_day.cutoff_hour'),
                'timezone' => (string) config('toolbox.work_day.timezone'),
            ],
            'max_milestones' => (int) config('toolbox.breaks.max_milestones'),
        ];
    }

    // -------------------------------------------------------------------------
    // The day
    // -------------------------------------------------------------------------

    /**
     * The day breakdown: where a user's break time went.
     *
     * @return array<string, mixed>
     */
    public function day(User $user, ?string $workDate = null, ?CarbonInterface $asOf = null): array
    {
        $asOf = $this->asOf($asOf);
        $workDate ??= $this->workDays->currentWorkDate($asOf);

        [$windowStart, $windowEnd] = $this->workDays->windowFor($workDate);

        $entries = $this->entries($user, $workDate);
        $totals = $this->totals($user, $workDate, $asOf, $entries);

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
            'entries' => $entries->map(fn (BreakEntry $entry) => $this->present($entry, $asOf))->all(),
            'milestones' => $this->milestones($user, $workDate, $totals['counted_minutes']),
        ];
    }

    /**
     * The day payload plus the ready-to-paste rendering.
     *
     * A separate call from day() because the day view is the one a client
     * polls, and it should not render a document on every tick.
     *
     * @return array<string, mixed>
     */
    public function export(User $user, ?string $workDate = null, ?CarbonInterface $asOf = null): array
    {
        $summary = $this->day($user, $workDate, $asOf);
        $summary['text'] = $this->renderText($summary);

        return $summary;
    }

    /**
     * The counted/excluded arithmetic for one work day, in one place - shared by
     * the day payload and the live broadcasts, because a broadcast figure that
     * disagreed with the summary figure is exactly the bug nobody would spot.
     *
     * @param  Collection<int, BreakEntry>|null  $entries  pre-loaded entries, when the caller already has them
     * @return array<string, mixed>
     */
    public function totals(User $user, string $workDate, ?CarbonInterface $asOf = null, ?Collection $entries = null): array
    {
        $asOf = $this->asOf($asOf);
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

        $allowanceMinutes = UserBreakSetting::forUser($user)->daily_allowance_minutes;
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
    private function entries(User $user, string $workDate): Collection
    {
        return BreakEntry::query()
            ->with(['breakType', 'notes.creator'])
            ->where('user_id', $user->id)
            ->forWorkDate($workDate)
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();
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

        // Counted first, then longest first - the order someone reads them.
        usort($categories, fn (array $a, array $b) => [$b['counts_toward_limit'], $b['seconds']] <=> [$a['counts_toward_limit'], $a['seconds']]);

        return $categories;
    }

    /**
     * @return array<string, mixed>
     */
    private function milestones(User $user, string $workDate, int $countedMinutes): array
    {
        $thresholds = BreakMilestone::thresholdsFor($user);

        return [
            'thresholds' => $thresholds,
            'fired' => BreakMilestoneFiring::forDay($user, $workDate)
                ->map(fn (BreakMilestoneFiring $firing) => [
                    'kind' => $firing->kind->value,
                    'threshold_minutes' => $firing->threshold_minutes,
                    // The true instant, which may be well before anyone looked.
                    'crossed_at' => $firing->crossed_at->toIso8601String(),
                    'noticed_at' => $firing->noticed_at->toIso8601String(),
                    'notified' => $firing->wasEmitted(),
                ])
                ->all(),
            'pending' => array_values(array_filter($thresholds, fn (int $minutes) => $minutes > $countedMinutes)),
        ];
    }

    // -------------------------------------------------------------------------
    // The text export
    //
    // A document someone pastes into a message to their manager, so the layout
    // is part of the contract and is byte-asserted in BreakSummaryTextTest.
    // Change it deliberately, and update that test with it.
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $summary
     */
    private function renderText(array $summary): string
    {
        $date = CarbonImmutable::parse($summary['work_date'])->format('l, j F Y');
        $generated = CarbonImmutable::parse($summary['generated_at']);
        $timezone = $summary['work_day']['timezone'];

        $lines = [
            "Break summary - {$date}",
            sprintf('%s · self-reported · generated %s %s', $summary['user']['name'], $generated->format('Y-m-d H:i'), $timezone),
            '',
            ...$this->countedBlock($summary),
            ...$this->excludedBlock($summary),
            ...$this->categoryBlock($summary),
            ...$this->noteBlock($summary),
            ...$this->milestoneLine($summary),
        ];

        if ($summary['has_active_break']) {
            $lines[] = sprintf('One break is still running; totals are as of %s %s.', $generated->format('H:i'), $timezone);
            $lines[] = '';
        }

        $lines[] = sprintf(
            'Work day runs %02d:00-%02d:00 %s. All times %s.',
            $summary['work_day']['cutoff_hour'],
            $summary['work_day']['cutoff_hour'],
            $timezone,
            $timezone,
        );

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<int, string>
     */
    private function countedBlock(array $summary): array
    {
        $counted = array_filter($summary['entries'], fn (array $e) => $e['counts_toward_limit']);

        $lines = [sprintf('COUNTED TOWARD THE %d-MINUTE ALLOWANCE', $summary['allowance_minutes'])];

        if ($counted === []) {
            $lines[] = '  (none)';
        }

        foreach ($counted as $entry) {
            $lines[] = $this->entryLine($entry);
        }

        $verdict = $summary['over_limit']
            ? sprintf('-  %dm OVER', $summary['over_minutes'])
            : sprintf('-  %dm left', $summary['remaining_minutes']);

        // The total comes from the day figure, never from adding the column
        // above: entry minutes are floored individually.
        $lines[] = '  '.str_repeat('-', 48);
        $lines[] = sprintf('  %-20s %3dm of %dm      %s', 'Counted total', $summary['counted_minutes'], $summary['allowance_minutes'], $verdict);
        $lines[] = '';

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<int, string>
     */
    private function excludedBlock(array $summary): array
    {
        $excluded = array_filter($summary['entries'], fn (array $e) => ! $e['counts_toward_limit']);

        if ($excluded === []) {
            return [];
        }

        return [
            'NOT COUNTED (special breaks)',
            ...array_map(fn (array $entry) => $this->entryLine($entry), array_values($excluded)),
            '  '.str_repeat('-', 48),
            sprintf('  %-20s %3dm', 'Excluded total', $summary['excluded_minutes']),
            '',
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<int, string>
     */
    private function categoryBlock(array $summary): array
    {
        if ($summary['categories'] === []) {
            return [];
        }

        $lines = ['BY CATEGORY'];

        foreach ($summary['categories'] as $category) {
            $lines[] = sprintf(
                '  %-22s %3dm   %s',
                $this->truncate($category['label'], 22),
                $category['minutes'],
                $category['counts_toward_limit'] ? 'counted' : 'excluded',
            );
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<int, string>
     */
    private function noteBlock(array $summary): array
    {
        $lines = [];

        foreach ($summary['entries'] as $entry) {
            foreach ($entry['notes'] as $note) {
                $lines[] = sprintf(
                    '  %s  %-14s "%s"',
                    CarbonImmutable::parse($entry['started_at'])->format('H:i'),
                    $this->truncate($entry['label'], 14),
                    $note['body'],
                );
            }
        }

        return $lines === [] ? [] : ['NOTES', ...$lines, ''];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<int, string>
     */
    private function milestoneLine(array $summary): array
    {
        if ($summary['milestones']['fired'] === []) {
            return [];
        }

        $reached = array_map(
            fn (array $f) => $f['kind'] === 'allowance'
                ? sprintf('allowance %dm', $f['threshold_minutes'])
                : sprintf('%dm', $f['threshold_minutes']),
            $summary['milestones']['fired'],
        );

        return ['Milestones reached: '.implode(', ', $reached), ''];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function entryLine(array $entry): string
    {
        $start = CarbonImmutable::parse($entry['started_at'])->format('H:i');

        $window = $entry['running']
            ? sprintf('%s-...', $start)
            : sprintf('%s-%s', $start, CarbonImmutable::parse($entry['ended_at'])->format('H:i'));

        // Worth saying out loud in a document a manager reads: a [manual] entry
        // was typed in afterwards rather than timed.
        $suffix = match (true) {
            $entry['running'] => ' (running)',
            $entry['source'] === 'manual' => ' [manual]',
            default => '',
        };

        return sprintf('  %-13s %3dm   %s%s', $window, $entry['duration_minutes'], $entry['label'], $suffix);
    }

    private function truncate(string $value, int $length): string
    {
        return mb_strlen($value) <= $length ? $value : mb_substr($value, 0, $length - 1).'…';
    }

    private function asOf(?CarbonInterface $asOf): CarbonImmutable
    {
        return $asOf === null ? CarbonImmutable::now() : CarbonImmutable::parse($asOf);
    }
}
