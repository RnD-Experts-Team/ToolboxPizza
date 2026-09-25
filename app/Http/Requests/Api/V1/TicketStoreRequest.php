<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Attachment;
use Illuminate\Foundation\Http\FormRequest;

class TicketStoreRequest extends FormRequest
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
            // The dashboard sends a KEY, never an id - one thing to hardcode,
            // and renaming the section's display name breaks nothing.
            'section_key' => ['required', 'string', 'exists:ticket_sections,key'],
            'title' => ['required', 'string', 'max:190'],
            'description' => ['required', 'string', 'max:20000'],
            'participants' => ['nullable', 'array', 'max:20'],
            'participants.*.user_id' => ['required_with:participants.*', 'integer', 'exists:users,id'],
            'participants.*.role' => ['required_with:participants.*', 'string', 'in:reader,responder,assignee'],
            'files' => ['nullable', 'array', 'max:'.Attachment::maxPerRequest()],
            'files.*' => Attachment::fileRules(),
            // notes[] and files[] are paired by index in multipart:
            // notes[0][files][0] lands on the first note.
            'notes' => ['nullable', 'array'],
            'notes.*.body' => ['required_with:notes.*', 'string', 'max:10000'],
            'notes.*.files' => ['nullable', 'array', 'max:'.Attachment::maxPerRequest()],
            'notes.*.files.*' => Attachment::fileRules(),
        ];
    }
}
