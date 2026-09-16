<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Where a ticket came from. The dashboard sends `key`; everything else here is
 * display copy the admin can change freely.
 */
class TicketSection extends Model
{
    protected $fillable = [
        'key',
        'name',
        'description',
        'display_order',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    /**
     * The levels this section sits under - there may be several, which is the
     * point: two different groups can own the same section without duplicating it.
     *
     * @return BelongsToMany<TicketLevel, $this>
     */
    public function levels(): BelongsToMany
    {
        return $this->belongsToMany(TicketLevel::class, 'ticket_level_section')->withTimestamps();
    }

    /** @return HasMany<TicketAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(TicketAssignment::class);
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * @param  Builder<TicketSection>  $query
     * @return Builder<TicketSection>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('active', true)->orderBy('display_order')->orderBy('id');
    }
}
