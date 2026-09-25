<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class TicketLevelSectionsRequest extends FormRequest
{
    /**
     * pizzasys has already authorised the route; what the caller may do to a
     * particular ticket is decided in the tickets services.
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
            // Whole-list replace: [] detaches every section from the level.
            'section_ids' => ['present', 'array'],
            'section_ids.*' => ['integer', 'exists:ticket_sections,id'],
        ];
    }
}
