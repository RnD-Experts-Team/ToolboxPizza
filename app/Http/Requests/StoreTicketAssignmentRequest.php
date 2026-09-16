<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreTicketAssignmentRequest extends FormRequest
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
            'user_id' => ['required', 'integer', 'exists:users,id'],
            // Exactly one of these. Checked advisorily below and authoritatively
            // in TicketAssignmentService - the house split.
            'ticket_section_id' => ['nullable', 'integer', 'exists:ticket_sections,id'],
            'ticket_level_id' => ['nullable', 'integer', 'exists:ticket_levels,id'],
            // TRUE  -> only stores this user actually holds
            // FALSE -> this section/level across every store
            'store_scoped' => ['nullable', 'boolean'],
        ];
    }

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
