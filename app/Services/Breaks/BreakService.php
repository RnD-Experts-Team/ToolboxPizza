<?php

namespace App\Services\Breaks;

use App\Enums\BreakSource;
use App\Exceptions\BreakException;
use App\Models\BreakEntry;
use App\Models\BreakMilestone;
use App\Models\BreakType;
use App\Models\Note;
use App\Models\User;
use App\Models\UserBreakSetting;
use App\Services\ToolboxEvents\ToolboxOutboxService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Every write in the breaks module, and every invariant behind it.
 *
 * Form Requests do shape validation and some advisory checks; this class is the
 * authoritative one, after the user's settings row is locked. That split is the
 * house pattern, not belt-and-braces: anything checked before the lock is
 * inherently TOCTOU-vulnerable.
 *
 * There is no manager or admin in this module: the person taking the breaks
 * sets the budget they are measured against, and the system reports rather
 * than polices.
 */
class BreakService
{
    /**
     * A running break has no end yet, so the overlap check treats it as
     * extending forever - far enough that no real entry can be placed after it
     * while it is open.
     */
    private const OPEN_ENDED = '9999-12-31 23:59:59';

    public function __construct(
        private readonly WorkDayResolver $workDays,
        private readonly BreakQueryService $reader,
        private readonly BreakMilestoneService $milestones,
        private readonly ToolboxOutboxService $outbox,
    ) {}

    // -------------------------------------------------------------------------
    // The timer
    // -------------------------------------------------------------------------

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
            $this->lockForUser($user);

            $this->assertNoRunningBreak($user);

            $type = $this->resolveType($breakTypeId, $otherLabel, forNewEntry: true);

            $this->assertNoOverlap($user->id, $startedAt, null);

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
            $this->broadcastEntry($user, $entry, 'break.started', $startedAt);

            return $entry;
        });
    }

    /**
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

            $this->broadcastEntry($entry->user, $entry, 'break.stopped', $endedAt);

            return $entry;
        });
    }

    // -------------------------------------------------------------------------
    // Entries
    // -------------------------------------------------------------------------

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
            $this->lockForUser($user);

            $this->assertUsableWindow($startedAt, $endedAt);

            $type = $this->resolveType((int) $data['break_type_id'], $otherLabel, forNewEntry: true);

            $this->assertNoOverlap($user->id, $startedAt, $endedAt);

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
            $this->broadcastEntry($user, $entry, 'break.updated');

            return $entry;
        });
    }

    /**
     * Correct an existing break. Overwrites in place: there is no revision
     * history in this module, by design.
     *
     * Key PRESENCE is load-bearing on `ended_at`: omitted leaves the end alone,
     * an explicit null reopens the break.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws BreakException
     */
    public function update(BreakEntry $entry, array $data): BreakEntry
    {
        return DB::transaction(function () use ($entry, $data) {
            $user = $entry->user;
            $this->lockForUser($user);
            $entry->refresh();

            $previousWorkDate = $entry->work_date->toDateString();

            $startedAt = array_key_exists('started_at', $data)
                ? CarbonImmutable::parse($data['started_at'])->utc()
                : $entry->started_at;

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

            $this->assertNoOverlap($user->id, $startedAt, $endedAt, $entry->id);

            $workDate = $this->workDays->workDateFor($startedAt);

            $entry->update([
                'break_type_id' => $type->id,
                'other_label' => $type->requires_custom_label ? $otherLabel : null,
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
                'work_date' => $workDate,
                'duration_seconds' => $endedAt === null ? null : $startedAt->diffInSeconds($endedAt, absolute: false),
                'counts_toward_limit' => $type->counts_toward_limit,
            ]);

            // Both days: the one it left may hold firings its recomputed total
            // no longer justifies, and the one it joined may now cross new
            // thresholds.
            foreach (array_unique([$previousWorkDate, $workDate]) as $date) {
                $this->milestones->reconcile($user, $date);
            }

            $this->broadcastEntry($user, $entry, 'break.updated', previousWorkDate: $previousWorkDate);

            return $entry;
        });
    }

    public function delete(BreakEntry $entry): void
    {
        DB::transaction(function () use ($entry) {
            $workDate = $entry->work_date->toDateString();
            $breakId = $entry->id;

            // Notes are polymorphic, so there is no foreign key and nothing
            // cascades. withTrashed()->forceDelete(), NOT delete(): Note uses
            // SoftDeletes but BreakEntry does not, so a plain delete would leave
            // soft-deleted notes pointing at a break that no longer exists -
            // and breaks:prune, which finds notes through their entries, could
            // never reach them.
            Note::query()
                ->where('notable_type', $entry->getMorphClass())
                ->where('notable_id', $entry->id)
                ->withTrashed()
                ->forceDelete();

            $user = $entry->user;
            $entry->delete();

            $this->milestones->reconcile($user, $workDate);

            if (config('toolbox.realtime.enabled')) {
                // The entry is already gone, so the client is told which id to
                // drop plus the totals that replace it.
                $this->outbox->broadcast([$user->id], 'break.deleted', [
                    'break_id' => $breakId,
                    'work_date' => $workDate,
                    'entry' => null,
                    'totals' => $this->reader->totals($user, $workDate),
                ]);
            }
        });
    }

    /**
     * Allowed while the break is still running and at any point afterwards, for
     * as long as the entry is retained - a user explaining a long break the next
     * morning is the normal case, not an edge one.
     */
    public function addNote(BreakEntry $entry, User $author, string $body): Note
    {
        return $entry->notes()->create([
            'body' => $body,
            'created_by' => $author->id,
        ])->load('creator');
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    public function updateAllowance(User $user, int $minutes): UserBreakSetting
    {
        $settings = UserBreakSetting::forUser($user);
        $settings->update(['daily_allowance_minutes' => $minutes]);

        return $settings;
    }

    /**
     * Replace the whole set - that is how the user thinks about it ("these are
     * my milestones"), and it keeps the client from having to diff.
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

            // firstOrCreate, not insert: an unchanged threshold keeps its row,
            // id and timestamps.
            foreach ($wanted as $threshold) {
                BreakMilestone::query()->firstOrCreate(['user_id' => $user->id, 'threshold_minutes' => $threshold]);
            }
        });

        return $wanted->all();
    }

    /**
     * Scoped to the caller: another user's milestone id is a 404, not a 403.
     */
    public function deleteMilestone(User $user, int $milestoneId): void
    {
        BreakMilestone::query()->where('user_id', $user->id)->findOrFail($milestoneId)->delete();
    }

    // -------------------------------------------------------------------------
    // Invariants
    // -------------------------------------------------------------------------

    /**
     * This is how "one open break at a time" is enforced: MySQL has no partial
     * unique index that could express "at most one NULL ended_at per user", so
     * writers serialise on the user's settings row first and then look for an
     * open break. forUser() runs first because you cannot lock a row that does
     * not exist yet.
     */
    private function lockForUser(User $user): void
    {
        UserBreakSetting::forUser($user);

        UserBreakSetting::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @throws BreakException
     */
    private function assertNoRunningBreak(User $user): void
    {
        $running = BreakEntry::query()
            ->with('breakType')
            ->where('user_id', $user->id)
            ->running()
            ->first();

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
        $oldest = $this->workDays->oldestRetainedWorkDate((int) config('toolbox.breaks.retention_days'));

        if ($this->workDays->workDateFor($startedAt) < $oldest) {
            throw BreakException::outsideRetentionWindow($oldest);
        }
    }

    /**
     * Works in ABSOLUTE INSTANTS and never filters by work_date. That is
     * deliberate and load-bearing: a break that runs 23:00 to 07:00 belongs to
     * yesterday's work day but must still block an 02:00 entry that belongs to
     * today's.
     *
     * Half-open intervals: two breaks that touch end-to-start do NOT overlap,
     * so ending one at 14:13 and starting the next at 14:13 is allowed.
     *
     * @throws BreakException
     */
    private function assertNoOverlap(int $userId, CarbonImmutable $startsAt, ?CarbonImmutable $endsAt, ?int $ignoreId = null): void
    {
        $conflicts = BreakEntry::query()
            ->with('breakType')
            ->where('user_id', $userId)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->where('started_at', '<', $endsAt?->toDateTimeString() ?? self::OPEN_ENDED)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $startsAt->toDateTimeString()))
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

        if ($conflicts !== []) {
            throw BreakException::overlap($conflicts);
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

    // -------------------------------------------------------------------------
    // Live state
    // -------------------------------------------------------------------------

    /**
     * Push a started / stopped / edited break to the user's socket. OFF by
     * default (TOOLBOX_REALTIME_ENABLED).
     *
     * Only state CHANGES go out - never the ticking clock. Between them the
     * client ticks locally from `started_at`, which is why every payload carries
     * the entry and the day totals rather than a countdown.
     */
    private function broadcastEntry(
        User $user,
        BreakEntry $entry,
        string $event,
        ?CarbonInterface $asOf = null,
        ?string $previousWorkDate = null,
    ): void {
        if (! config('toolbox.realtime.enabled')) {
            return;
        }

        $asOf = $asOf === null ? CarbonImmutable::now() : CarbonImmutable::parse($asOf);
        $workDate = $entry->work_date->toDateString();

        $data = [
            'break_id' => $entry->id,
            'work_date' => $workDate,
            'entry' => $this->reader->present($entry, $asOf),
            'totals' => $this->reader->totals($user, $workDate, $asOf),
        ];

        // An edit that moves started_at across the cutoff moves the entry to a
        // different work day. A client showing the old day has to drop it and
        // restate that day's figures, so both travel in the one message.
        if ($previousWorkDate !== null && $previousWorkDate !== $workDate) {
            $data['previous_work_date'] = $previousWorkDate;
            $data['previous_totals'] = $this->reader->totals($user, $previousWorkDate, $asOf);
        }

        $this->outbox->broadcast([$user->id], $event, $data);
    }

    private function instant(?CarbonInterface $at): CarbonImmutable
    {
        return $at === null ? CarbonImmutable::now()->utc() : CarbonImmutable::parse($at)->utc();
    }
}
