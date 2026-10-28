<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A personal reminder — only its owner ever sees it.
 *
 * scheduled ──(remind_at passes, calendar:dispatch-reminders)──► sent ──► done
 *     ▲                                                           │
 *     └──────────────────────── snooze ◄──────────────────────────┘
 */
class CalendarReminder extends Model
{
    protected $fillable = [
        'calendar_event_id', 'user_id', 'remind_at', 'target_ref', 'target_title', 'target_link',
        'status', 'sent_at', 'snooze_count',
    ];

    protected function casts(): array
    {
        return [
            'remind_at' => 'datetime',
            'sent_at' => 'datetime',
            'snooze_count' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'calendar_event_id');
    }
}
