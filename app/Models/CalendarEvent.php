<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One thing on the calendar: a plain event, a task, or a reminder (`type`).
 *
 * A task also has a CalendarTask row and one CalendarTaskAssignment per store;
 * a reminder also has a CalendarReminder row. CalendarEventController creates
 * them together, so `type = task` always means the task row exists.
 */
class CalendarEvent extends Model
{
    public const TYPES = ['event', 'task', 'reminder'];

    protected $fillable = [
        'type', 'title', 'description', 'category', 'priority',
        'date', 'start_time', 'end_time', 'link', 'created_by', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    public function task(): HasOne
    {
        return $this->hasOne(CalendarTask::class);
    }

    public function reminder(): HasOne
    {
        return $this->hasOne(CalendarReminder::class);
    }

    /** The stores it shows in (events and tasks). */
    public function stores(): HasMany
    {
        return $this->hasMany(CalendarEventStore::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }
}
