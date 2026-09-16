<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTicketSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is delegated wholesale to pizzasys via
        // AuthTokenStoreScopeMiddleware (ext.authorized), which has already run.
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // `key` is absent on purpose: the dashboard hardcodes it, so renaming
            // it would orphan every box still sending the old one. Retire the
            // section and add a new one instead.
            'name' => ['sometimes', 'string', 'max:190'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
