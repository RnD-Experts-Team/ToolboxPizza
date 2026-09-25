<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One value, at one (row, column).
 *
 * There is deliberately no getCellValue()-style helper on WorkbookRow: the
 * reference implementation had one that queried the relation rather than
 * reading it, so rendering a grid cost rows x columns queries. Readers take the
 * preloaded map from WorkbookRowService::grid() instead.
 */
class WorkbookCell extends Model
{
    protected $fillable = [
        'row_id',
        'column_id',
        'value_text',
        'value_number',
        'value_date',
        'value_bool',
    ];

    /**
     * value_number stays a string under 'decimal:6' - casting it to float would
     * quietly lose precision on the 20,6 the column actually stores. The
     * presenter decides how to render it.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_number' => 'decimal:6',
            'value_date' => 'datetime',
            'value_bool' => 'boolean',
        ];
    }

    /** @return BelongsTo<WorkbookRow, $this> */
    public function row(): BelongsTo
    {
        return $this->belongsTo(WorkbookRow::class, 'row_id');
    }

    /** @return BelongsTo<WorkbookColumn, $this> */
    public function column(): BelongsTo
    {
        return $this->belongsTo(WorkbookColumn::class, 'column_id');
    }
}
