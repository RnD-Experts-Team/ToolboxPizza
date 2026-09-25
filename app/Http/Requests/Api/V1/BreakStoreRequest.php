<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class BreakStoreRequest extends FormRequest
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
            'other_label' => ['nullable', 'string', 'max:120'],
            'started_at' => ['required', 'date'],
            // ADVISORY ONLY. These give a clean 422 with a field name for the
            // obvious mistakes; the authoritative future / overlap / retention
            // checks run in BreakService after the user's row is locked,
            // because anything checked here is inherently TOCTOU-vulnerable.
            'ended_at' => ['required', 'date', 'after:started_at'],
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
