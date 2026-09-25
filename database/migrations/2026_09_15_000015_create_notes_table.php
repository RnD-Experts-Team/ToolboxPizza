<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            // The house polymorphic note table, trimmed to notes only.
            //
            // The sibling version has an Attachment twin. That half is not free:
            // it brings a disk, upload validation, an orphaned-file prune story
            // and a second table, all permanently maintained for something this
            // slice does not need - annotating a break is text. The morph column
            // names are identical to MaintenancePizza's, so adding attachments
            // later is an additive migration plus a second trait, not a rename.
            $table->id();

            // Today only BreakEntry. Kept polymorphic rather than a plain
            // break_entry_id so the tickets slice can attach notes without a
            // migration.
            //
            // NOTE: a morph has no foreign key, so nothing cascades. Every
            // deletion path must remove notes explicitly - see
            // BreakService::delete() and PruneBreaksCommand.
            $table->morphs('notable');

            // Unused today; kept because the sibling has it and typed notes
            // (a closing note, a reason) are the obvious next thing to want.
            $table->string('type')->nullable();

            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Soft deletes from day one. MaintenancePizza had to retrofit these
            // (2026_09_10_000051) after finding its edit path hard-deleted notes
            // its own revision snapshots still pointed at. Cheaper to start with
            // the column. breaks:prune uses forceDelete(): 30-day retention
            // means gone, not hidden.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
