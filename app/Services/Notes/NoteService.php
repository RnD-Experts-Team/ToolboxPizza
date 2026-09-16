<?php

namespace App\Services\Notes;

use App\Models\Note;
use App\Services\Attachments\AttachmentService;
use App\Services\Attachments\StagedUpload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Free-text notes on any notable record, now with files.
 *
 * Extracted from the inline logic that was living in BreakNoteController, so
 * tickets and breaks annotate the same way and a note can carry a photo of the
 * thing it is explaining.
 */
class NoteService
{
    public function __construct(private readonly AttachmentService $attachments) {}

    /**
     * @param  array<int, StagedUpload>  $staged
     */
    public function store(Model $owner, string $body, ?string $type = null, array $staged = []): Note
    {
        $note = $owner->notes()->make(['body' => $body, 'type' => $type]);
        $note->created_by = Auth::id();
        $note->save();

        $this->attachments->commit($note, $staged);

        return $note->load(['attachments.creator', 'creator']);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Note $note): array
    {
        return [
            'id' => $note->id,
            'type' => $note->type,
            'body' => $note->body,
            'attachments' => $this->attachments->presentMany($note),
            'created_by' => $note->created_by,
            'creator' => $note->relationLoaded('creator') && $note->creator
                ? ['id' => $note->creator->id, 'name' => $note->creator->name]
                : null,
            'created_at' => $note->created_at?->toIso8601String(),
            'updated_at' => $note->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function presentMany(Model $owner): ?array
    {
        if (! $owner->relationLoaded('notes')) {
            return null;
        }

        return $owner->notes->map(fn (Note $n) => $this->present($n))->all();
    }
}
