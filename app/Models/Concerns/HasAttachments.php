<?php

namespace App\Models\Concerns;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Gives a model the polymorphic file relation.
 *
 * Paired with HasNotes rather than merged into one trait, because a break wants
 * notes and no files while a ticket wants both - and the notes-only case was
 * already shipped.
 *
 * NOTHING CASCADES on a morph. Every deletion path has to remove attachments
 * itself, and attachments:prune sweeps whatever slips through.
 */
trait HasAttachments
{
    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
