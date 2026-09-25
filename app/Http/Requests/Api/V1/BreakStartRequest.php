<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class BreakStartRequest extends FormRequest
{
    /**
     * pizzasys has already authorised the route; what the caller may do to a
     * particular record is decided in the service.
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
            'break_type_id' => ['required', 'integer', 'exists:break_types,id'],
            // Required only for the one catalogue row that asks for it. That
            // needs a database read, so BreakService enforces it; this is shape.
            'other_label' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * A whitespace-only label is no label.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('other_label') && trim((string) $this->input('other_label')) === '') {
            $this->merge(['other_label' => null]);
        }
    }
}
