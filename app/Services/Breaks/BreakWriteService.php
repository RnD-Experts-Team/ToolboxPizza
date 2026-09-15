<?php

namespace App\Services\Breaks;

use App\Enums\BreakSource;
use App\Models\BreakEntry;
use App\Models\BreakType;
use App\Models\Note;
use App\Models\User;
use App\Services\Breaks\Exceptions\BreakException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Every write invariant for break entries lives here.
 *
 * Form Requests do shape validation and some advisory checks; this class is the
 * authoritative one. That split is the house pattern, not belt-and-braces:
 * StoreStockMovementRequest in MaintenancePizza carries the same note, that its
 * own check is "ADVISORY ONLY ... inherently TOCTOU-vulnerable ... the
 * authoritative check lives in the service, after the row is locked."
 */
class BreakWriteService
{
    public function __construct(
        private readonly WorkDayResolver $workDays,
        private readonly BreakSettingsService $settings,
        private readonly BreakOverlapGuard $overlaps,
        private readonly BreakMilestoneEvaluator $milestones,
        private readonly BreakBroadcaster $broadcaster,
    ) {}

    /**
     * Start timing a break now.
     *
     * @throws BreakException
     */
    public function start(User $user, int $breakTypeId, ?string $otherLabel = null, ?CarbonInterface $at = null): BreakEntry
    {
        $startedAt = $this->instant($at);

        return DB::transaction(function () use ($user, $breakTypeId, $otherLabel, $startedAt) {
            // Serialise every writer for this user before looking for an open
            // break. Without the lock, two concurrent starts both see "nothing
            // running" and both insert.
            $this->settings->lockForUser($user);

            $this->assertNoRunningBreak($user);

            $type = $this->resolveType($breakTypeId, $otherLabel, forNewEntry: true);

            $this->overlaps->assertNoOverlap($user->id, $startedAt, null);

            $entry = BreakEntry::query()->create([
                'user_id' => $user->id,
                'break_type_id' => $type->id,
                'other_label' => $type->requires_custom_label ? $otherLabel : null,
                'started_at' => $startedAt,
                'ended_at' => null,
                'work_date' => $this->workDays->workDateFor($startedAt),
                'duration_seconds' => null,
                'counts_toward_limit' => $type->counts_toward_limit,
                'source' => BreakSource::Timer,
            ]);

            // Inside the transaction, so a firing and the outbox row announcing
            // it commit together with the break that caused them.
            $this->milestones->evaluate($user, $entry->work_date->toDateString(), $startedAt);

            // The client starts its own ticker from started_at; nothing pushes
            // the clock itself.
            $this->broadcaster->entryChanged($user, $entry, BreakBroadcaster::STARTED, $startedAt);

            return $entry;
        });
    }

    /**
     * Stop a running break.
     *
     * @throws BreakException
     */
    public function stop(BreakEntry $entry, ?CarbonInterface $at = null): BreakEntry
    {
        $endedAt = $this->instant($at);

        return DB::transaction(function () use ($entry, $endedAt) {
            $entry->refresh();

            if (! $entry->isRunning()) {
                throw BreakException::notRunning($entry->id);
            }

            if ($endedAt->lessThan($entry->started_at)) {
                throw BreakException::endsBeforeStart();
            }

            $entry->update([
                'ended_at' => $endedAt,
                'duration_seconds' => $entry->started_at->diffInSeconds($endedAt, absolute: false),
            ]);

            // work_date is NOT recomputed: the entry belongs to the day it
            // started in, even when it ends after the next cutoff.
            $this->milestones->evaluate($entry->user, $entry->work_date->toDateString(), $endedAt);

            $this->broadcaster->entryChanged($entry->user, $entry, BreakBroadcaster::STOPPED, $endedAt);

            return $entry;
        });
    }

    /**
     * Record a break the user forgot to time, start and end together.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws BreakException
     */
    public function storeManual(User $user, array $data): BreakEntry
    {
        $startedAt = CarbonImmutable::parse($data['started_at'])->utc();
        $endedAt = CarbonImmutable::parse($data['ended_at'])->utc();
        $otherLabel = $data['other_label'] ?? null;

        return DB::transaction(function () use ($user, $data, $startedAt, $endedAt, $otherLabel) {
            $this->settings->lockForUser($user);

            $this->assertUsableWindow($startedAt, $endedAt);

            $type = $this->resolveType((int) $data['break_type_id'], $otherLabel, forNewEntry: true);

            $this->overlaps->assertNoOverlap($user->id, $startedAt, $endedAt);

            $entry = BreakEntry::query()->create([
                'user_id' => $user->id,
                'break_type_id' => $type->id,
                'other_label' => $type->requires_custom_label ? $otherLabel : null,
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
                'work_date' => $this->workDays->workDateFor($startedAt),
                'duration_seconds' => $startedAt->diffInSeconds($endedAt, absolute: false),
                'counts_toward_limit' => $type->counts_toward_limit,
                'source' => BreakSource::Manual,
            ]);

            // Back-dated time can push a past day over a threshold it never
            // crossed at the time, so this evaluates too.
            $this->milestones->evaluate($user, $entry->work_date->toDateString());

            // UPDATED, not STARTED: a manual entry is already finished, so the
            // client upserts it by id rather than starting a ticker.
            $this->broadcaster->entryChanged($user, $entry, BreakBroadcaster::UPDATED);

            return $entry;
        });
    }

    /**
     * Correct an existing break. Overwrites in place: there is no revision
     * history in this service, by design.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: BreakEntry, 1: array<int, string>} the entry, and every
     *                                                     work date it touched - the old one too, when a start moved
     *                                                     across a cutoff, so callers can reconcile both.
     *
     * @throws BreakException
     */
    public function update(BreakEntry $entry, array $data): array
    {
        return DB::transaction(function () use ($entry, $data) {
            $user = $entry->user;
            $this->settings->lockForUser($user);
            $entry->refresh();

            $previousWorkDate = $entry->work_date->toDateString();

            $startedAt = array_key_exists('started_at', $data)
                ? CarbonImmutable::parse($data['started_at'])->utc()
                : $entry->started_at;

            // A running break stays running unless the caller supplies an end.
            $endedAt = array_key_exists('ended_at', $data)
                ? ($data['ended_at'] === null ? null : CarbonImmutable::parse($data['ended_at'])->utc())
                : $entry->ended_at;

            $typeId = (int) ($data['break_type_id'] ?? $entry->break_type_id);
            $otherLabel = array_key_exists('other_label', $data) ? $data['other_label'] : $entry->other_label;

            if ($endedAt !== null) {
                $this->assertUsableWindow($startedAt, $endedAt);
            } elseif ($startedAt->greaterThan(CarbonImmutable::now())) {
                throw BreakException::startsInFuture();
            }

            // An edit may keep a type that has since been retired; only a NEW
            // entry is barred from choosing one.
            $type = $this->resolveType($typeId, $otherLabel, forNewEntry: $typeId !== $entry->break_type_id);

            $this->overlaps->assertNoOverlap($user->id, $startedAt, $endedAt, $entry->id);

            $workDate = $this->workDays->workDateFor($startedAt);

            $entry->update([
                'break_type_id' => $type->id,
                'other_label' => $type->requires_custom_label ? $otherLabel : null,
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
                'work_date' => $workDate,
                'duration_seconds' => $endedAt === null
                    ? null
                    : $startedAt->diffInSeconds($endedAt, absolute: false),
                'counts_toward_limit' => $type->counts_toward_limit,
            ]);

            $touched = array_values(array_unique([$previousWorkDate, $workDate]));

            // Both days: the one it left may hold firings its recomputed total
            // no longer justifies, and the one it joined may now cross new
            // thresholds.
            foreach ($touched as $date) {
                $this->milestones->reconcile($user, $date);
            }

            $this->broadcaster->entryChanged(
                $user,
                $entry,
                BreakBroadcaster::UPDATED,
                previousWorkDate: $previousWorkDate,
            );

            return [$entry, $touched];
        });
    }

    /**
     * @return string the work date the entry was on, so callers can reconcile it
     */
    public function delete(BreakEntry $entry): string
    {
        return DB::transaction(function () use ($entry) {
            $workDate = $entry->work_date->toDateString();
            $breakId = $entry->id;

            // Notes are polymorphic, so there is no foreign key and nothing
            // cascades. Deleting them here is not optional.
            //
            // withTrashed()->forceDelete(), NOT delete(): Note uses SoftDeletes
            // but BreakEntry does not, so a plain delete would leave the notes
            // behind as soft-deleted rows pointing at a break_entries.id that no
            // longer exists. breaks:prune could never reach them either - it
            // finds notes via entries that still exist - so they would
            // accumulate permanently. Same pattern as PruneBreaksCommand.
            Note::query()
                ->where('notable_type', $entry->getMorphClass())
                ->where('notable_id', $entry->id)
                ->withTrashed()
                ->forceDelete();

            $user = $entry->user;
            $entry->delete();

            $this->milestones->reconcile($user, $workDate);

            $this->broadcaster->entryDeleted($user, $breakId, $workDate);

            return $workDate;
        });
    }

    /**
     * The user's currently running break, if any.
     */
    public function running(User $user): ?BreakEntry
    {
        return BreakEntry::query()
            ->with(['breakType', 'notes.creator'])
            ->where('user_id', $user->id)
            ->running()
            ->orderByDesc('started_at')
            ->first();
    }

    /**
     * @throws BreakException
     */
    private function assertNoRunningBreak(User $user): void
    {
        $running = $this->running($user);

        if ($running !== null) {
            throw BreakException::alreadyOnBreak([
                'id' => $running->id,
                'label' => $running->label(),
                'started_at' => $running->started_at->toIso8601String(),
            ]);
        }
    }

    /**
     * @throws BreakException
     */
    private function assertUsableWindow(CarbonImmutable $startedAt, CarbonImmutable $endedAt): void
    {
        if ($endedAt->lessThanOrEqualTo($startedAt)) {
            throw BreakException::endsBeforeStart();
        }

        if ($startedAt->greaterThan(CarbonImmutable::now())) {
            throw BreakException::startsInFuture();
        }

        // Anything older than the retention horizon would be created now and
        // deleted by tonight's prune, which is worse than refusing it.
        $oldest = $this->workDays->oldestRetainedWorkDate(
            (int) config('toolbox.breaks.retention_days'),
        );

        if ($this->workDays->workDateFor($startedAt) < $oldest) {
            throw BreakException::outsideRetentionWindow($oldest);
        }
    }

    /**
     * Resolve the catalog row and enforce the catalog-or-free-text rule.
     *
     * @throws BreakException
     */
    private function resolveType(int $breakTypeId, ?string $otherLabel, bool $forNewEntry): BreakType
    {
        $type = BreakType::query()->findOrFail($breakTypeId);

        if ($forNewEntry && ! $type->active) {
            throw BreakException::typeInactive($type->name);
        }

        $label = is_string($otherLabel) ? trim($otherLabel) : null;

        if ($type->requires_custom_label && ($label === null || $label === '')) {
            throw BreakException::customLabelRequired($type->name);
        }

        if (! $type->requires_custom_label && $label !== null && $label !== '') {
            throw BreakException::customLabelNotAllowed($type->name);
        }

        return $type;
    }

    private function instant(?CarbonInterface $at): CarbonImmutable
    {
        return $at === null
            ? CarbonImmutable::now()->utc()
            : CarbonImmutable::parse($at)->utc();
    }
}
