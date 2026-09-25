<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class TicketUpdateRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'max:190'],
            'description' => ['sometimes', 'string', 'max:20000'],
            'section_key' => ['sometimes', 'string', 'exists:ticket_sections,key'],
        ];
    }
}
