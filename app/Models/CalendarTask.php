<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What only a task needs. Its deadline is the event's date + start_time.
 *
 * A store task keeps its state per store (CalendarTaskAssignment); a private
 * task has no stores and is simply done or not (`done_at`).
 */
class CalendarTask extends Model
{
    public const PROOFS = ['none', 'note'];

    protected $fillable = ['calendar_event_id', 'proof', 'requires_review', 'done_at'];

    protected function casts(): array
    {
        return ['requires_review' => 'boolean', 'done_at' => 'datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'calendar_event_id');
    }
}
