<?php

namespace App\Models;

use App\Enums\WorkbookVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A node in the folder tree. Holds child folders and workbooks, and carries a
 * visibility tag that CAPS everything beneath it - see
 * WorkbookAccessService for what that means in practice.
 */
class WorkbookFolder extends Model
{
    protected $fillable = [
        'parent_id',
        'store_id',
        'created_by',
        'name',
        'description',
        'visibility',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visibility' => WorkbookVisibility::class,
        ];
    }

    /** @return BelongsTo<WorkbookFolder, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<WorkbookFolder, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<Workbook, $this> */
    public function workbooks(): HasMany
    {
        return $this->hasMany(Workbook::class, 'workbook_folder_id');
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
