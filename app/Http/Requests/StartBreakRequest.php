<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StartBreakRequest extends FormRequest
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
            'break_type_id' => ['required', 'integer', 'exists:break_types,id'],
            // Required only for the one catalog row that asks for it. That rule
            // needs a database read, so it is enforced authoritatively in
            // BreakWriteService::resolveType(); this is shape only.
            'other_label' => ['nullable', 'string', 'max:120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('other_label') && trim((string) $this->input('other_label')) === '') {
            $this->merge(['other_label' => null]);
        }
    }
}
