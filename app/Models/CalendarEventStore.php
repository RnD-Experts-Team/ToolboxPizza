<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A calendar event shown in one store — and, for a task, that store's assignment. */
class CalendarEventStore extends Model
{
    protected $table = 'calendar_event_store';

    protected $fillable = ['calendar_event_id', 'store_id'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'calendar_event_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function assignment(): HasOne
    {
        return $this->hasOne(CalendarTaskAssignment::class);
    }
}
