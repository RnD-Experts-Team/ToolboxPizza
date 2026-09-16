<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAttachments;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTicketAttachmentRequest extends FormRequest
{
    use ValidatesAttachments;

    public function authorize(): bool
    {
        // Authorization is delegated wholesale to pizzasys via
        // AuthTokenStoreScopeMiddleware (ext.authorized), which has already run.
        // Per-ticket ability is enforced by TicketAccessResolver in the
        // controller, not here.
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge([
            'files' => ['required', 'array', 'min:1', 'max:'.$this->maxFiles()],
        ], ['files.*' => $this->fileRules()]);
    }
}
