<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\WorkbookVisibility;
use Illuminate\Foundation\Http\FormRequest;

class WorkbookUpdateRequest extends FormRequest
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
            // Columns are NOT here - they have their own whole-list replace.
            'name' => ['sometimes', 'required', 'string', 'max:190'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ...WorkbookVisibility::rules(),
        ];
    }
}
