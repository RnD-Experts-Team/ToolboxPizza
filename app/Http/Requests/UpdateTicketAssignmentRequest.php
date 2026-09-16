<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTicketAssignmentRequest extends FormRequest
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
            // The target is not movable - delete and recreate instead. Changing
            // it in place would silently re-route everything this person owns.
            'store_scoped' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
