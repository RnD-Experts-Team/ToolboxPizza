<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\WorkbookVisibility;
use App\Models\WorkbookColumn;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class WorkbookRowRequest extends FormRequest
{
    /**
     * pizzasys has already authorised the route; what the caller may do to a
     * particular folder, workbook or row is decided in the workbook services.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // An all-blank row is legitimate - a line to fill in later. On an
            // update this is PARTIAL: only the columns named are touched.
            'cells' => ['sometimes', 'array'],
            'cells.*' => ['nullable', 'string', 'max:10000'],
            ...WorkbookVisibility::rules(),
        ];
    }

    /**
     * Every `cells` key must be a column of THIS workbook. An after-hook, not a
     * rule, because Laravel cannot constrain the KEY of `cells.*` - and the
     * reference implementation checked nothing here at all, so a forged key
     * pointing at another workbook's column inserted happily.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $cells = $this->input('cells');

            if (! is_array($cells) || $cells === []) {
                return;
            }

            $submitted = array_map('intval', array_keys($cells));

            $owned = WorkbookColumn::query()
                ->where('workbook_id', (int) $this->route('workbookId'))
                ->whereIn('id', $submitted)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            foreach (array_diff($submitted, $owned) as $columnId) {
                $v->errors()->add('cells.'.$columnId, 'That column does not belong to this workbook.');
            }
        });
    }
}
