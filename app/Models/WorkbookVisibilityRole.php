<?php

namespace App\Models;

use App\Enums\WorkbookVisibleType;
use Illuminate\Database\Eloquent\Model;

/**
 * One role name attached to one store_role_* tag.
 *
 * Read almost exclusively through hand-written SQL in the visibility clause;
 * this model exists for the write path and for presenting a resource's tag.
 */
class WorkbookVisibilityRole extends Model
{
    protected $fillable = [
        'visible_type',
        'visible_id',
        'role_name',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visible_type' => WorkbookVisibleType::class,
            'visible_id' => 'integer',
        ];
    }
}
