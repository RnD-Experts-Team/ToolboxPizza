<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\WorkbookVisibility;
use Illuminate\Foundation\Http\FormRequest;

class WorkbookFolderUpdateRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:190'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Moving it. The cycle check needs the whole tree, so the service
            // refuses that, not a rule.
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:workbook_folders,id'],
            ...WorkbookVisibility::rules(),
        ];
    }
}
