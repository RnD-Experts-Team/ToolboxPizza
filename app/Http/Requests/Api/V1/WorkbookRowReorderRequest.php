<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkbookRowReorderRequest extends FormRequest
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
            // Rows in the order they should appear; rows not named keep theirs.
            'row_ids' => ['required', 'array', 'min:1', 'max:500'],
            'row_ids.*' => ['integer', Rule::exists('workbook_rows', 'id')->where('workbook_id', (int) $this->route('workbookId'))],
        ];
    }
}
