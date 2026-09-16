<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            // Files hung off a ticket, a response, or a note.
            //
            // The column names match MaintenancePizza's exactly, as the notes
            // migration promised they would - so this is additive rather than a
            // rename, and a model can gain attachments by swapping one trait.
            $table->id();

            $table->morphs('attachable');

            // NOT in MaintenancePizza. Recorded per row so that moving to a
            // different disk later does not strand every existing file: old
            // rows keep pointing at the disk they were written to.
            $table->string('disk', 32)->default('public');

            $table->string('path');
            $table->string('original_name')->nullable();

            // Sniffed with getMimeType(), NOT getClientMimeType(). The client
            // one is attacker-controlled, and this value is what the allowlist
            // was checked against - storing the claim rather than the fact
            // would make the allowlist decorative.
            $table->string('mime_type', 191)->nullable();

            $table->unsignedBigInteger('size')->nullable();

            // sha256. Lets the prune tell two byte-identical uploads apart and
            // makes integrity checkable without re-reading every file.
            $table->string('checksum', 64)->nullable();

            // MaintenancePizza passes created_by into create() but omits it from
            // $fillable, so every attachment there has a null uploader. Here it
            // is fillable AND set explicitly - the bug was exactly one of those
            // two being missing.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The row outlives the delete until attachments:prune unlinks the
            // bytes. MaintenancePizza retrofitted this after discovering its
            // edit path hard-deleted rows whose files stayed on disk forever.
            $table->softDeletes();

            $table->index('created_by');
            // The prune's disk-versus-rows diff.
            $table->index(['disk', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
