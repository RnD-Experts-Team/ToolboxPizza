<?php

namespace App\Services\Breaks;

use App\Models\BreakEntry;
use App\Models\Note;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Reads and presentation for break entries.
 *
 * The presenter lives on the service rather than in a JsonResource, matching
 * the two newest sibling services (StorageLocationService::present(), etc.).
 */
class BreakQueryService
{
    public function __construct(private readonly WorkDayResolver $workDays) {}

    /**
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
            ->when(
                filled($filters['from'] ?? null),
                fn ($q) => $q->where('work_date', '>=', $filters['from']),
            )
            ->when(
                filled($filters['to'] ?? null),
                fn ($q) => $q->where('work_date', '<=', $filters['to']),
            )
            ->when(
                filled($filters['source'] ?? null),
                fn ($q) => $q->where('source', $filters['source']),
            )
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
        $asOf = $asOf === null ? CarbonImmutable::now() : CarbonImmutable::parse($asOf);

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
            // Floor, never round: see BreakDayReportService for why entry
            // minutes are not expected to sum to the day total.
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
}
