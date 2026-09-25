<?php

namespace App\Http\Requests\Api\V1;

use App\Models\WorkbookColumn;
use Illuminate\Foundation\Http\FormRequest;

class WorkbookColumnsRequest extends FormRequest
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
        // Read from the route, never the body, so a forged field cannot widen
        // the workbook the ids are checked against.
        return WorkbookColumn::rules((int) $this->route('workbookId'));
    }
}
