<?php

namespace App\Models\Concerns;

use App\Models\Note;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Gives a model the polymorphic free-text note relation.
 *
 * The house trait in MaintenancePizza is HasNotesAndAttachments; this is the
 * notes half. A break is annotated with words, not files, so the attachment
 * side - with its disk, upload validation and orphaned-file prune - is not
 * carried here. The morph column names match, so adding it later is additive.
 */
trait HasNotes
{
    /** @return MorphMany<Note, $this> */
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable');
    }
}
