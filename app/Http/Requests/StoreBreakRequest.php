<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The "I forgot to time it" entry: both ends supplied by hand.
 */
class StoreBreakRequest extends FormRequest
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
            'other_label' => ['nullable', 'string', 'max:120'],
            'started_at' => ['required', 'date'],
            // ADVISORY ONLY, and so are the future/overlap/retention checks in
            // BreakWriteService's sibling rules. These give a clean 422 with a
            // field name for the obvious mistakes; the authoritative versions
            // run inside the service after the user's settings row is locked,
            // because anything checked here is inherently TOCTOU-vulnerable.
            // Do not delete those as redundant.
            'ended_at' => ['required', 'date', 'after:started_at'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('other_label') && trim((string) $this->input('other_label')) === '') {
            $this->merge(['other_label' => null]);
        }
    }
}
