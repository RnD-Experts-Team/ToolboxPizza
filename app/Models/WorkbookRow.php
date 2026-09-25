<?php

namespace App\Models;

use App\Enums\WorkbookVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One record in a workbook.
 *
 * Carries its OWN store and visibility, not the workbook's. That is what lets a
 * shared workbook hold per-store lines that each store sees only its own of.
 */
class WorkbookRow extends Model
{
    protected $fillable = [
        'workbook_id',
        'store_id',
        'created_by',
        'visibility',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visibility' => WorkbookVisibility::class,
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Workbook, $this> */
    public function workbook(): BelongsTo
    {
        return $this->belongsTo(Workbook::class);
    }

    /** @return HasMany<WorkbookCell, $this> */
    public function cells(): HasMany
    {
        return $this->hasMany(WorkbookCell::class, 'row_id');
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
