<?php

namespace App\Http\Requests\Concerns;

/**
 * The house upload block, in one place.
 *
 * MaintenancePizza validates `['file','max:10240']` and nothing else, on a
 * publicly-served disk - so an uploaded .html or .svg becomes stored XSS on our
 * own origin. The allowlist and the count cap are the difference between
 * "public files" and "public executable files"; they do not change the storage
 * model, only what is allowed through the door.
 */
trait ValidatesAttachments
{
    /**
     * @return array<int, string>
     */
    protected function fileRules(): array
    {
        return [
            'file',
            'max:'.(int) config('toolbox.tickets.attachments.max_kilobytes'),
            // mimetypes:, NOT mimes: - the check must be on the sniffed content
            // type, never on a client-supplied filename extension.
            'mimetypes:'.implode(',', (array) config('toolbox.tickets.attachments.allowed_mimetypes')),
        ];
    }

    protected function maxFiles(): int
    {
        return (int) config('toolbox.tickets.attachments.max_per_request');
    }

    /**
     * Top-level files plus per-note files, keyed so the controller can pair them
     * back up by array index.
     *
     * @return array<string, mixed>
     */
    protected function attachmentRules(bool $withNotes = true): array
    {
        $rules = [
            'files' => ['nullable', 'array', 'max:'.$this->maxFiles()],
            'files.*' => $this->fileRules(),
        ];

        if ($withNotes) {
            $rules['notes'] = ['nullable', 'array'];
            $rules['notes.*.body'] = ['required_with:notes.*', 'string', 'max:10000'];
            $rules['notes.*.files'] = ['nullable', 'array', 'max:'.$this->maxFiles()];
            $rules['notes.*.files.*'] = $this->fileRules();
        }

        return $rules;
    }
}
