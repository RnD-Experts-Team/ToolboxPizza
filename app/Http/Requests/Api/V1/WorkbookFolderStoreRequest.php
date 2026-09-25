<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\WorkbookVisibility;
use Illuminate\Foundation\Http\FormRequest;

class WorkbookFolderStoreRequest extends FormRequest
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
            // No parent is a root folder, the ordinary case.
            'parent_id' => ['nullable', 'integer', 'exists:workbook_folders,id'],
            ...WorkbookVisibility::rules(),
        ];
    }
}
