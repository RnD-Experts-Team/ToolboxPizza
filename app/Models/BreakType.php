<?php

namespace App\Models;

use App\Enums\BreakTypeGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A seeded catalog row. Retired with `active = false` rather than deleted,
 * because 30 days of break history point at these rows and must keep rendering
 * their label.
 */
class BreakType extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'slug',
        'name',
        'group',
        'counts_toward_limit',
        'requires_custom_label',
        'sort_order',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'group' => BreakTypeGroup::class,
            'counts_toward_limit' => 'boolean',
            'requires_custom_label' => 'boolean',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Selectable types, in the order the picker shows them.
     *
     * @param  Builder<BreakType>  $query
     * @return Builder<BreakType>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('active', true)->orderBy('sort_order')->orderBy('id');
    }
}
