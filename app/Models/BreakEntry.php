<?php

namespace App\Models;

use App\Enums\BreakSource;
use App\Models\Concerns\HasNotes;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\BreakEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One break.
 *
 * `ended_at === null` means it is still running; there is no status column,
 * because a status and a nullable end are the same fact.
 *
 * @property-read Collection<int, Note> $notes
 */
class BreakEntry extends Model
{
    /** @use HasFactory<BreakEntryFactory> */
    use HasFactory, HasNotes;

    protected $fillable = [
        'user_id',
        'break_type_id',
        'other_label',
        'started_at',
        'ended_at',
        'work_date',
        'duration_seconds',
        'counts_toward_limit',
        'source',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'work_date' => 'immutable_date',
            'duration_seconds' => 'integer',
            'counts_toward_limit' => 'boolean',
            'source' => BreakSource::class,
        ];
    }

    /**
     * Force work_date to be stored as a bare Y-m-d.
     *
     * Without this the two drivers disagree: MySQL coerces a DATE column to
     * '2026-09-16', while SQLite - which is dynamically typed, and is what the
     * test suite runs on - keeps whatever Eloquent handed it, namely
     * '2026-09-16 00:00:00'. Every range comparison on this column
     * (`where('work_date', '<=', '2026-09-16')`, the prune horizon) is a string
     * comparison there, so the datetime form silently fails to match and a
     * green test suite would be telling us nothing about production.
     *
     * Writing the canonical form ourselves makes both drivers identical and
     * keeps the comparisons index-friendly.
     */
    public function setWorkDateAttribute(mixed $value): void
    {
        $this->attributes['work_date'] = CarbonImmutable::parse($value)->format('Y-m-d');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<BreakType, $this> */
    public function breakType(): BelongsTo
    {
        return $this->belongsTo(BreakType::class);
    }

    public function isRunning(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * How long this break has lasted, in seconds.
     *
     * For a finished break that is the stored duration. For a running one it is
     * elapsed-so-far, which is why every caller that can see a running break
     * passes the same $asOf instant: a day summary must not have its entries
     * measured against slightly different "nows".
     */
    public function elapsedSeconds(?CarbonInterface $asOf = null): int
    {
        if ($this->ended_at !== null) {
            return (int) $this->duration_seconds;
        }

        $asOf = $asOf === null ? CarbonImmutable::now() : CarbonImmutable::parse($asOf);

        // Clamped at zero: a clock skew or a start nudged into the future must
        // never contribute negative time to a day total.
        return max(0, $this->started_at->diffInSeconds($asOf, absolute: false));
    }

    /**
     * What to call this break. Resolves the catalog-or-free-text split once, so
     * no client has to branch on it.
     */
    public function label(): string
    {
        return $this->other_label ?? (string) $this->breakType?->name;
    }

    /**
     * The one break a user currently has open, if any.
     *
     * @param  Builder<BreakEntry>  $query
     * @return Builder<BreakEntry>
     */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /**
     * @param  Builder<BreakEntry>  $query
     * @return Builder<BreakEntry>
     */
    public function scopeForWorkDate(Builder $query, string $workDate): Builder
    {
        return $query->where('work_date', $workDate);
    }
}
