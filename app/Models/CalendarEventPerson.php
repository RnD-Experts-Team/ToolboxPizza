<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person an event or task is for (`scope = people`).
 *
 * For a task this is that person's own state, with the same steps as a store's
 * (CalendarTaskAssignment): open → in_progress → submitted → approved, or
 * returned. For a plain event `status` is null.
 */
class CalendarEventPerson extends Model
{
    protected $table = 'calendar_event_people';

    protected $fillable = [
        'calendar_event_id', 'user_id', 'status', 'started_at', 'submitted_at',
        'submitted_late', 'completion_note', 'reviewed_at', 'reviewed_by', 'return_reason',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'submitted_late' => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'calendar_event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
