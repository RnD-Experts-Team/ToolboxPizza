<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's responsibility for a section or a level.
 *
 * Never names stores. `store_scoped` decides whether the holder is limited to
 * stores they actually have access to - resolved against the replicated
 * user_store_roles when a ticket is routed, not when the assignment is made.
 */
class TicketAssignment extends Model
{
    protected $fillable = [
        'user_id',
        'ticket_section_id',
        'ticket_level_id',
        'store_scoped',
        'active',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'store_scoped' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<TicketSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(TicketSection::class, 'ticket_section_id');
    }

    /** @return BelongsTo<TicketLevel, $this> */
    public function level(): BelongsTo
    {
        return $this->belongsTo(TicketLevel::class, 'ticket_level_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * How this grant was made, for the /recipients endpoint - so an admin
     * looking at a surprising recipient list can see WHY someone is on it.
     */
    public function via(): string
    {
        return $this->ticket_section_id !== null
            ? 'section'
            : 'level:'.$this->ticket_level_id;
    }
}
