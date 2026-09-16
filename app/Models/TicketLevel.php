<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A grouping over sections. Levels nest, so an assignment high up reaches every
 * section beneath it.
 *
 * Ancestry walks live in TicketLevelGraph, not here - a relation-based walk
 * would issue a query per hop and has nowhere to put the cycle guard.
 */
class TicketLevel extends Model
{
    protected $fillable = [
        'parent_id',
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

    /** @return BelongsTo<TicketLevel, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(TicketLevel::class, 'parent_id');
    }

    /** @return HasMany<TicketLevel, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(TicketLevel::class, 'parent_id');
    }

    /** @return BelongsToMany<TicketSection, $this> */
    public function sections(): BelongsToMany
    {
        return $this->belongsToMany(TicketSection::class, 'ticket_level_section')->withTimestamps();
    }

    /** @return HasMany<TicketAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(TicketAssignment::class);
    }

    /**
     * @param  Builder<TicketLevel>  $query
     * @return Builder<TicketLevel>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('active', true)->orderBy('display_order')->orderBy('id');
    }
}
