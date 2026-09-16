<?php

namespace App\Services\Attachments;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Files on tickets, responses and notes.
 *
 * THE THREE-STEP SHAPE IS THE POINT. MaintenancePizza writes bytes inside the
 * domain transaction, so a rollback discards the rows and leaves the files on
 * disk with nothing pointing at them, forever. Splitting it means:
 *
 *   stage()   - write bytes, no rows. Outside the transaction.
 *   commit()  - insert rows. Inside it.
 *   discard() - unlink, from the controller's catch.
 *
 * A process killed between stage() and commit() is not covered by discard(), so
 * attachments:prune has a grace window for exactly that case. Both are needed.
 */
class AttachmentService
{
    /**
     * Write files to disk without recording anything.
     *
     * @param  array<int, mixed>  $files
     * @return array<int, StagedUpload>
     */
    public function stage(array $files): array
    {
        $disk = $this->disk();
        $staged = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            // Dated directories so no single folder grows without bound, and a
            // ULID so two uploads of the same filename never collide. The
            // extension is preserved so the served Content-Type and the bytes
            // agree.
            $extension = $file->getClientOriginalExtension();
            $name = (string) Str::ulid().($extension !== '' ? '.'.$extension : '');
            $directory = 'ticket-attachments/'.now()->format('Y/m');

            $path = $file->storeAs($directory, $name, ['disk' => $disk]);

            $staged[] = new StagedUpload(
                disk: $disk,
                path: $path,
                originalName: $file->getClientOriginalName(),
                // Sniffed, not client-supplied - this is the value the mime
                // allowlist actually checked.
                mimeType: $file->getMimeType(),
                size: (int) $file->getSize(),
                checksum: $this->checksum($disk, $path),
            );
        }

        return $staged;
    }

    /**
     * Record staged files against their owner. Call inside the transaction.
     *
     * @param  array<int, StagedUpload>  $staged
     * @return array<int, Attachment>
     */
    public function commit(Model $owner, array $staged): array
    {
        $created = [];

        foreach ($staged as $upload) {
            $attachment = $owner->attachments()->make([
                'disk' => $upload->disk,
                'path' => $upload->path,
                'original_name' => $upload->originalName,
                'mime_type' => $upload->mimeType,
                'size' => $upload->size,
                'checksum' => $upload->checksum,
            ]);

            // Explicit, the way NoteService does it - belt and braces with
            // $fillable, because MaintenancePizza's bug was having only one.
            $attachment->created_by = Auth::id();
            $attachment->save();

            $created[] = $attachment;
        }

        return $created;
    }

    /**
     * Unlink staged bytes whose transaction never committed.
     *
     * @param  array<int, StagedUpload>  $staged
     */
    public function discard(array $staged): void
    {
        foreach ($staged as $upload) {
            Storage::disk($upload->disk)->delete($upload->path);
        }
    }

    /**
     * Remove attachments and their files for good.
     *
     * Unlink FIRST, then delete the row. That order is deliberate: a failure
     * after the unlink leaves a row pointing at a missing file, which the next
     * prune run tidies harmlessly. The other order leaves a file nothing points
     * at, which nothing will ever find again.
     *
     * @param  iterable<int, Attachment>  $attachments
     */
    public function purge(iterable $attachments): int
    {
        $count = 0;

        foreach ($attachments as $attachment) {
            Storage::disk($attachment->disk ?: 'public')->delete($attachment->path);
            $attachment->forceDelete();
            $count++;
        }

        return $count;
    }

    /**
     * Delete every attachment belonging to one owner. Morphs do not cascade, so
     * every deletion path has to call this.
     */
    public function purgeFor(Model $owner): int
    {
        return $this->purge($owner->attachments()->withTrashed()->get());
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Attachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'url' => $attachment->url,
            'original_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size' => $attachment->size,
            'created_by' => $attachment->created_by,
            'creator' => $attachment->relationLoaded('creator') && $attachment->creator
                ? ['id' => $attachment->creator->id, 'name' => $attachment->creator->name]
                : null,
            'created_at' => $attachment->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function presentMany(Model $owner): ?array
    {
        if (! $owner->relationLoaded('attachments')) {
            return null;
        }

        return $owner->attachments->map(fn (Attachment $a) => $this->present($a))->all();
    }

    public function disk(): string
    {
        return (string) config('toolbox.tickets.attachments.disk', 'public');
    }

    private function checksum(string $disk, string $path): ?string
    {
        $stream = Storage::disk($disk)->readStream($path);

        if ($stream === null || $stream === false) {
            return null;
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }
}
