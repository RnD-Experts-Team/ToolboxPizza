<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * A file hung off a ticket, a response, or a note.
 *
 * Served straight off the public disk via the storage:link symlink, matching
 * MaintenancePizza. That means the bytes are readable by anyone holding the URL
 * - no bearer token, no pizzasys check - which is the one part of this API not
 * covered by ext.authorized. Deliberate, and documented in the README.
 */
class Attachment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'checksum',
        // In $fillable AND set explicitly by the service. MaintenancePizza does
        // only the latter half and so records no uploader at all.
        'created_by',
    ];

    /** The frontend uses this directly; there is no download endpoint. */
    protected $appends = ['url'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Resolved against the row's own disk rather than a hardcoded one, so a file
     * written before a disk change still resolves.
     */
    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk ?: 'public')->url($this->path);
    }
}
