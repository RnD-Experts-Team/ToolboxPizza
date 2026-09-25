<?php

namespace App\Models;

use App\Enums\WorkbookColumnType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

/**
 * One column definition. Carries no data itself - the values live in
 * workbook_cells, in the slot this column's type names.
 */
class WorkbookColumn extends Model
{
    protected $fillable = [
        'workbook_id',
        'name',
        'type',
        'options',
        'required',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => WorkbookColumnType::class,
            'options' => 'array',
            'required' => 'boolean',
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
        return $this->hasMany(WorkbookCell::class, 'column_id');
    }

    /**
     * The validation for a column list, shared by creating a workbook and by the
     * whole-list column replace.
     *
     * With a workbook id, submitted column ids must belong to THAT workbook:
     * `exists:workbook_columns,id` alone would let a submission name somebody
     * else's column, and the write would then re-parent it.
     *
     * @return array<string, mixed>
     */
    public static function rules(?int $workbookId = null): array
    {
        $rules = [
            'columns' => ['required', 'array', 'min:1', 'max:100'],
            'columns.*.name' => ['required', 'string', 'max:190'],
            'columns.*.type' => ['sometimes', Rule::enum(WorkbookColumnType::class)],
            'columns.*.required' => ['sometimes', 'boolean'],
            // Only a choice column reads these; elsewhere they are ignored rather
            // than 422'd, so a client resending the whole form is not punished.
            'columns.*.options' => ['array', 'max:200', 'required_if:columns.*.type,'.WorkbookColumnType::Select->value],
            'columns.*.options.*' => ['string', 'max:190'],
        ];

        if ($workbookId !== null) {
            $rules['columns.*.id'] = ['nullable', 'integer', Rule::exists('workbook_columns', 'id')->where('workbook_id', $workbookId)];
        }

        return $rules;
    }
}
