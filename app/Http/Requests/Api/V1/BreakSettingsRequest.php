<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class BreakSettingsRequest extends FormRequest
{
    /**
     * pizzasys has already authorised the route; what the caller may do to a
     * particular record is decided in the service.
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
            // A full day of break is absurd but not this service's business to
            // refuse - the limit is soft by design.
            'daily_allowance_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ];
    }
}
