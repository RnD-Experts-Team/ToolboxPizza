<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\WorkbookVisibility;
use App\Models\WorkbookColumn;
use Illuminate\Foundation\Http\FormRequest;

class WorkbookStoreRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:2000'],
            // A workbook with no columns is a table with nowhere to put a value.
            ...WorkbookColumn::rules(),
            ...WorkbookVisibility::rules(),
        ];
    }
}
