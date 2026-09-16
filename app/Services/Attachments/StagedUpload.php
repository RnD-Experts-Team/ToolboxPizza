<?php

namespace App\Services\Attachments;

/**
 * Bytes already on disk, with no database row yet.
 *
 * The gap between writing a file and committing its row is where
 * MaintenancePizza leaks: it uploads inside the transaction, so a rollback
 * discards the row and leaves the bytes behind forever. Naming that in-between
 * state gives the controller something it can hand to discard().
 */
final readonly class StagedUpload
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $originalName,
        public ?string $mimeType,
        public int $size,
        public ?string $checksum,
    ) {}
}
