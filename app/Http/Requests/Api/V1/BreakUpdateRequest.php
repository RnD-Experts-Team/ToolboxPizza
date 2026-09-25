<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A partial update. BreakService tells an omitted `ended_at` (leave the end
 * alone) from an explicit null (reopen the break) by key presence, which is why
 * nothing here strips nulls.
 */
class BreakUpdateRequest extends FormRequest
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
            'break_type_id' => ['sometimes', 'integer', 'exists:break_types,id'],
            'other_label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'started_at' => ['sometimes', 'date'],
            'ended_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
