<?php

namespace App\Models;

use App\Enums\MilestoneKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A threshold that has already fired for a user on a work day.
 *
 * The unique index on (user_id, work_date, kind, threshold_minutes) is what
 * makes firing idempotent under concurrent evaluation - see the migration.
 */
class BreakMilestoneFiring extends Model
{
    protected $fillable = [
        'user_id',
        'work_date',
        'kind',
        'threshold_minutes',
        'crossed_at',
        'noticed_at',
        'counted_seconds_at_cross',
        'outbox_event_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_date' => 'immutable_date',
            'kind' => MilestoneKind::class,
            'crossed_at' => 'immutable_datetime',
            'noticed_at' => 'immutable_datetime',
            'threshold_minutes' => 'integer',
            'counted_seconds_at_cross' => 'integer',
        ];
    }

    /**
     * Same reason as BreakEntry: MySQL coerces a DATE column, SQLite does not,
     * and every lookup here is an equality match on this column.
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

    /**
     * Whether this firing has already been announced. An announced firing is
     * never reaped, because a notification cannot be unsent.
     */
    public function wasEmitted(): bool
    {
        return $this->outbox_event_id !== null;
    }
}
