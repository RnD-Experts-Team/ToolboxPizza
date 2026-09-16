<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SyncTicketLevelSectionsRequest extends FormRequest
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
            // Whole-list replace, mirroring break-milestones: an empty array
            // detaches every section from this level.
            'section_ids' => ['present', 'array'],
            'section_ids.*' => ['integer', 'exists:ticket_sections,id'],
        ];
    }
}
