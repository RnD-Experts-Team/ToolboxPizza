<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Attachment;
use Illuminate\Foundation\Http\FormRequest;

class TicketAttachmentRequest extends FormRequest
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
            'files' => ['required', 'array', 'min:1', 'max:'.Attachment::maxPerRequest()],
            'files.*' => Attachment::fileRules(),
        ];
    }
}
