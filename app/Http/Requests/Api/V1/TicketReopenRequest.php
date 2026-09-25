<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class TicketReopenRequest extends FormRequest
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
            // Always required: reopening says the last answer was wrong, and the
            // person who gave it deserves to know why.
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
