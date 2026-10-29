<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What only a task needs. Its deadline is the event's date + start_time. */
class CalendarTask extends Model
{
    public const PROOFS = ['none', 'note'];

    protected $fillable = ['calendar_event_id', 'proof', 'requires_review'];

    protected function casts(): array
    {
        return ['requires_review' => 'boolean'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'calendar_event_id');
    }
}
