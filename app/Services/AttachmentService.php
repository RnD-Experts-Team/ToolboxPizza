<?php

namespace App\Services;

use App\Models\Attachment;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Files on tickets, responses and notes.
 *
 * THE SHAPE IS THE POINT. MaintenancePizza writes bytes inside the domain
 * transaction, so a rollback discards the rows and leaves the files on disk
 * with nothing pointing at them, forever. Here the bytes are written BEFORE the
 * transaction opens and their rows inside it; if anything throws, the bytes are
 * unlinked again. See withStaged().
 *
 * A process killed between staging and committing is not covered by that, so
 * attachments:prune has a grace window for exactly that case. Both are needed.
 */
class AttachmentService
{
    /**
     * Stage `$files`, run `$work` with the staged rows, and unlink them again if
     * `$work` throws - so a rollback can never strand bytes on disk.
     *
     * @template T
     *
     * @param  array<int, mixed>  $files
     * @param  Closure(array<int, array<string, mixed>>): T  $work
     * @return T
     */
    public function withStaged(array $files, Closure $work): mixed
    {
        $staged = $this->stage($files);

        try {
            return $work($staged);
        } catch (Throwable $e) {
            $this->discard($staged);

            throw $e;
        }
    }

    /**
     * Write files to disk without recording anything. Each staged file is the
     * attribute set its attachment row will be created with.
     *
     * @param  array<int, mixed>  $files
     * @return array<int, array<string, mixed>>
     */
    public function stage(array $files): array
    {
        $disk = $this->disk();
        $staged = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            // Dated directories so no folder grows without bound, a ULID so two
            // uploads of the same filename never collide, and the extension kept
            // so the served Content-Type and the bytes agree.
            $extension = $file->getClientOriginalExtension();
            $name = (string) Str::ulid().($extension !== '' ? '.'.$extension : '');
            $path = $file->storeAs('ticket-attachments/'.now()->format('Y/m'), $name, ['disk' => $disk]);

            $staged[] = [
                'disk' => $disk,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                // Sniffed, not client-supplied - this is the value the mime
                // allowlist actually checked.
                'mime_type' => $file->getMimeType(),
                'size' => (int) $file->getSize(),
                'checksum' => $this->checksum($disk, $path),
            ];
        }

        return $staged;
    }

    /**
     * Record staged files against their owner. Call inside the transaction.
     *
     * @param  array<int, array<string, mixed>>  $staged
     * @return array<int, Attachment>
     */
    public function commit(Model $owner, array $staged): array
    {
        $created = [];

        foreach ($staged as $upload) {
            $attachment = $owner->attachments()->make($upload);

            // Explicit as well as fillable - MaintenancePizza's bug was having
            // only one of the two.
            $attachment->created_by = Auth::id();
            $attachment->save();

            $created[] = $attachment;
        }

        return $created;
    }

    /**
     * Unlink staged bytes whose transaction never committed.
     *
     * @param  array<int, array<string, mixed>>  $staged
     */
    public function discard(array $staged): void
    {
        foreach ($staged as $upload) {
            Storage::disk($upload['disk'])->delete($upload['path']);
        }
    }

    /**
     * Remove attachments and their files for good.
     *
     * Unlink FIRST, then delete the row. A failure after the unlink leaves a row
     * pointing at a missing file, which the next prune tidies harmlessly. The
     * other order leaves a file nothing points at, which nothing will ever find.
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
