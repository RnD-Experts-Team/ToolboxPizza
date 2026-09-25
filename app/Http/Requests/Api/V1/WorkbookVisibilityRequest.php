<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\WorkbookVisibility;
use Illuminate\Foundation\Http\FormRequest;

class WorkbookVisibilityRequest extends FormRequest
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
        // Required here, unlike everywhere else: this endpoint exists only to
        // set it, so omitting it is a malformed request rather than a no-op.
        return WorkbookVisibility::rules(required: true);
    }
}
