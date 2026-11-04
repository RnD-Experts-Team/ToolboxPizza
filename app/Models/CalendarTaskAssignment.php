<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A task's status in one store.
 *
 *   open ──start──► in_progress ──submit──► submitted ──approve──► approved
 *     ▲    └────────────submit──────────────┘    │
 *     └──────────────── return (reason) ◄────────┘
 *
 * "Overdue" is never stored: open / in_progress / returned past the deadline.
 */
class CalendarTaskAssignment extends Model
{
    public const OPEN = ['open', 'in_progress', 'returned'];

    protected $fillable = [
        'calendar_event_store_id', 'status', 'started_at', 'submitted_at', 'submitted_by',
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

    public function eventStore(): BelongsTo
    {
        return $this->belongsTo(CalendarEventStore::class, 'calendar_event_store_id');
    }
}
