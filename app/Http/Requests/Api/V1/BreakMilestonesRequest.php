<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class BreakMilestonesRequest extends FormRequest
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
            // Whole-list replace: [] clears every threshold, which is how a user
            // turns milestones off. Duplicates are collapsed by the service.
            'thresholds' => ['present', 'array', 'max:'.(int) config('toolbox.breaks.max_milestones')],
            'thresholds.*' => ['integer', 'min:1', 'max:1440'],
        ];
    }
}
