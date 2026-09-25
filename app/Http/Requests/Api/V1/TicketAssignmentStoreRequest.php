<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TicketAssignmentStoreRequest extends FormRequest
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
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'ticket_section_id' => ['nullable', 'integer', 'exists:ticket_sections,id'],
            'ticket_level_id' => ['nullable', 'integer', 'exists:ticket_levels,id'],
            'store_scoped' => ['nullable', 'boolean'],
        ];
    }

    /**
     * ADVISORY - a clean 422 with a field name. TicketCatalogService holds the
     * authoritative section-XOR-level check.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $section = $this->input('ticket_section_id');
            $level = $this->input('ticket_level_id');

            if ($section === null && $level === null) {
                $validator->errors()->add('ticket_section_id', 'Give either a section or a level.');
            }

            if ($section !== null && $level !== null) {
                $validator->errors()->add('ticket_section_id', 'Give a section or a level, not both.');
            }
        });
    }
}
