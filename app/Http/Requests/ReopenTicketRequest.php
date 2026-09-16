<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReopenTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is delegated wholesale to pizzasys via
        // AuthTokenStoreScopeMiddleware (ext.authorized), which has already run.
        // Per-ticket ability is enforced by TicketAccessResolver in the
        // controller, not here.
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Always required: reopening says the last answer was wrong, and the
            // person who gave it deserves to know why.
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
