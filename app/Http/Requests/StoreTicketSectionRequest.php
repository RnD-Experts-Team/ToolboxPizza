<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTicketSectionRequest extends FormRequest
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
            // The key the dashboard will hardcode. Lowercase dotted/dashed so it
            // reads as an identifier rather than a label.
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9._-]*$/', 'unique:ticket_sections,key'],
            'name' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:2000'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
