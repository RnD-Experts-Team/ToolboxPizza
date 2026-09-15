<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBreakSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is delegated wholesale to pizzasys via
        // AuthTokenStoreScopeMiddleware (ext.authorized), which has already run.
        // A user can only ever reach their own settings — the controller reads
        // Auth::user(), never an id from the request.
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Upper bound is a full day of break, which is absurd but not this
            // service's business to refuse — the limit is soft by design.
            'daily_allowance_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ];
    }
}
