<?php

namespace App\Services\Breaks;

use Carbon\CarbonImmutable;

/**
 * Renders a day summary as plain text the user can paste into a message to
 * their manager.
 *
 * The layout is part of the contract - it is a document someone sends to a
 * person - so it is byte-asserted in BreakTextRendererTest. Change it
 * deliberately, and update that test with it.
 *
 * It never adds up its own minute column to produce the day total: entry
 * minutes are floored individually, so their sum can be lower than the day
 * figure. Totals always come from the summary.
 */
class BreakTextRenderer
{
    /**
     * @param  array<string, mixed>  $summary
     */
    public function render(array $summary): string
    {
        $lines = [];

        $date = CarbonImmutable::parse($summary['work_date'])->format('l, j F Y');
        $generated = CarbonImmutable::parse($summary['generated_at']);
        $timezone = $summary['work_day']['timezone'];

        $lines[] = "Break summary - {$date}";
        $lines[] = sprintf(
            '%s · self-reported · generated %s %s',
            $summary['user']['name'],
            $generated->format('Y-m-d H:i'),
            $timezone,
        );
        $lines[] = '';

        $lines = array_merge($lines, $this->countedBlock($summary));
        $lines = array_merge($lines, $this->excludedBlock($summary));
        $lines = array_merge($lines, $this->categoryBlock($summary));
        $lines = array_merge($lines, $this->noteBlock($summary));
        $lines = array_merge($lines, $this->milestoneBlock($summary));

        if ($summary['has_active_break']) {
            $lines[] = sprintf(
                'One break is still running; totals are as of %s %s.',
                $generated->format('H:i'),
                $timezone,
            );
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
        $counted = array_values(array_filter($summary['entries'], fn (array $e) => $e['counts_toward_limit']));

        $lines = [sprintf('COUNTED TOWARD THE %d-MINUTE ALLOWANCE', $summary['allowance_minutes'])];

        if ($counted === []) {
            $lines[] = '  (none)';
        }

        foreach ($counted as $entry) {
            $lines[] = $this->entryLine($entry);
        }

        $lines[] = '  '.str_repeat('-', 48);

        $verdict = $summary['over_limit']
            ? sprintf('-  %dm OVER', $summary['over_minutes'])
            : sprintf('-  %dm left', $summary['remaining_minutes']);

        $lines[] = sprintf(
            '  %-20s %3dm of %dm      %s',
            'Counted total',
            $summary['counted_minutes'],
            $summary['allowance_minutes'],
            $verdict,
        );
        $lines[] = '';

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<int, string>
     */
    private function excludedBlock(array $summary): array
    {
        $excluded = array_values(array_filter($summary['entries'], fn (array $e) => ! $e['counts_toward_limit']));

        if ($excluded === []) {
            return [];
        }

        $lines = ['NOT COUNTED (special breaks)'];

        foreach ($excluded as $entry) {
            $lines[] = $this->entryLine($entry);
        }

        $lines[] = '  '.str_repeat('-', 48);
        $lines[] = sprintf('  %-20s %3dm', 'Excluded total', $summary['excluded_minutes']);
        $lines[] = '';

        return $lines;
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

        if ($lines === []) {
            return [];
        }

        return array_merge(['NOTES'], $lines, ['']);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<int, string>
     */
    private function milestoneBlock(array $summary): array
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

        $suffix = '';

        if ($entry['running']) {
            $suffix = ' (running)';
        } elseif ($entry['source'] === 'manual') {
            // Worth saying out loud in a document a manager reads: this one was
            // typed in afterwards rather than timed.
            $suffix = ' [manual]';
        }

        return sprintf(
            '  %-13s %3dm   %s%s',
            $window,
            $entry['duration_minutes'],
            $entry['label'],
            $suffix,
        );
    }

    private function truncate(string $value, int $length): string
    {
        return mb_strlen($value) <= $length ? $value : mb_substr($value, 0, $length - 1).'…';
    }
}
