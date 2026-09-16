<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTicketRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'max:190'],
            'description' => ['sometimes', 'string', 'max:20000'],
            // Re-sectioning re-routes the ticket, so only an assignee may do it
            // - enforced in the controller, not here.
            'section_key' => ['sometimes', 'string', 'exists:ticket_sections,key'],
        ];
    }
}
