<?php

namespace App\Models;

use App\Enums\WorkbookVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One user-defined table, always inside a folder.
 */
class Workbook extends Model
{
    protected $fillable = [
        'workbook_folder_id',
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
    public function folder(): BelongsTo
    {
        return $this->belongsTo(WorkbookFolder::class, 'workbook_folder_id');
    }

    /**
     * Ordering baked into the relation: a workbook's columns are only ever
     * meaningful in their display order, and forgetting the orderBy at one call
     * site would render a grid with shuffled headers.
     *
     * @return HasMany<WorkbookColumn, $this>
     */
    public function columns(): HasMany
    {
        return $this->hasMany(WorkbookColumn::class, 'workbook_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    /** @return HasMany<WorkbookRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(WorkbookRow::class, 'workbook_id');
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
