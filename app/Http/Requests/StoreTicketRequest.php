<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAttachments;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTicketRequest extends FormRequest
{
    use ValidatesAttachments;

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
        return array_merge([
            // The dashboard sends a KEY, never an id - one thing to hardcode,
            // and renaming the section's display name breaks nothing.
            'section_key' => ['required', 'string', 'exists:ticket_sections,key'],
            'title' => ['required', 'string', 'max:190'],
            'description' => ['required', 'string', 'max:20000'],
            // Loop people in at creation. The reporter is allowed to do this for
            // their own ticket; adding an exception assignee later needs the
            // manage-participants ability.
            'participants' => ['nullable', 'array', 'max:20'],
            'participants.*.user_id' => ['required_with:participants.*', 'integer', 'exists:users,id'],
            'participants.*.role' => ['required_with:participants.*', 'string', 'in:reader,responder,assignee'],
        ], $this->attachmentRules());
    }
}
