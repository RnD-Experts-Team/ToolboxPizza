<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReplaceBreakMilestonesRequest extends FormRequest
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
            // Whole-list replacement: an empty array clears every threshold,
            // which is how a user turns milestones off.
            'thresholds' => ['present', 'array', 'max:'.(int) config('toolbox.breaks.max_milestones')],
            // Duplicates are accepted and collapsed by the service rather than
            // rejected — the user asked for a set, and re-sending one is not an
            // error worth an error message.
            'thresholds.*' => ['integer', 'min:1', 'max:1440'],
        ];
    }
}
