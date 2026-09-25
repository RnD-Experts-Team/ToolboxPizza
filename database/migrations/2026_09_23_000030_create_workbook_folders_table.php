<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workbook_folders', function (Blueprint $table) {
            // The top of the workbook hierarchy: folders nest, hold workbooks,
            // and carry the visibility tag that caps everything beneath them.
            $table->id();

            // A tree, unlike ticket_levels which is a graph - a folder has at
            // most one parent, the same as every file manager anyone has used.
            // Cascade: deleting a folder is understood to take the subtree.
            // The cycle guard lives in WorkbookAccessService, not here.
            $table->foreignId('parent_id')->nullable()
                ->constrained('workbook_folders')->cascadeOnDelete();

            // The store this was created AT. Not "the store that can see it" -
            // that is what `visibility` decides. restrictOnDelete because the
            // folder's whole store_* audience is defined by this row.
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();

            // The owner carve-out reads this: created_by may always view and
            // edit their own node, whatever the tag says. Nullable + nullOnDelete
            // matches the estate's audit-column convention, and an orphaned
            // folder simply loses its owner privilege rather than vanishing.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name', 190);
            $table->text('description')->nullable();

            // WorkbookVisibility. A string rather than an enum column: MySQL
            // enums cannot be extended without an ALTER, and adding a tag later
            // should be a PHP change, not a migration against a live table.
            $table->string('visibility', 32)->default('owner_only');

            $table->timestamps();

            // The two real read paths: "folders at this store" and "children of".
            $table->index(['store_id', 'parent_id']);
            $table->index(['created_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workbook_folders');
    }
};
