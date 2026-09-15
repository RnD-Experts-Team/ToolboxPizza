<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Correcting a break after the fact. Every field is optional, so a client can
 * send only what changed.
 *
 * `ended_at` may be sent as null to reopen a break; omitting it leaves the end
 * alone. The service distinguishes the two by key presence, which is why
 * nothing here strips nulls.
 */
class UpdateBreakRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is delegated wholesale to pizzasys via
        // AuthTokenStoreScopeMiddleware (ext.authorized), which has already run.
        // Record ownership is enforced by ResolvesOwnBreak, not here.
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'break_type_id' => ['sometimes', 'integer', 'exists:break_types,id'],
            'other_label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'started_at' => ['sometimes', 'date'],
            // Advisory. BreakWriteService re-checks ordering, the future and the
            // retention horizon against the values it actually ends up with,
            // which may combine a new start with an existing end.
            'ended_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
