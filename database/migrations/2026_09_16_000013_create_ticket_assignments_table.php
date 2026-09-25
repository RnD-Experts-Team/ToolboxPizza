<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_assignments', function (Blueprint $table) {
            // Who is responsible for what.
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // EXACTLY ONE of these is set. A section grant covers that section;
            // a level grant covers every section beneath it, however deep.
            //
            // Enforced in TicketCatalogService rather than by a CHECK
            // constraint: CHECK is not portable to older MySQL and SQLite's
            // cannot be altered later. No sibling service uses them either.
            $table->foreignId('ticket_section_id')->nullable()->constrained('ticket_sections')->cascadeOnDelete();
            $table->foreignId('ticket_level_id')->nullable()->constrained('ticket_levels')->cascadeOnDelete();

            // THE WHOLE STORE STORY, and the reason assignments never name
            // stores one by one.
            //
            // TRUE  -> this person only receives and sees tickets for stores
            //          they actually hold a grant for, checked against the
            //          replicated user_store_roles at resolution time.
            // FALSE -> this section/level across every store.
            //
            // Defaults true: fail closed. A misconfigured assignment should
            // reach too few people, not every store in the estate.
            $table->boolean('store_scoped')->default(true);

            // Suspend without losing the configuration.
            $table->boolean('active')->default(true);

            // The only audit this table carries.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // NULLs are distinct in both MySQL and SQLite, so each of these is
            // effectively a partial unique: level rows (section NULL) never
            // collide in the first, section rows never collide in the second.
            $table->unique(['user_id', 'ticket_section_id'], 'ticket_assignments_user_section_unique');
            $table->unique(['user_id', 'ticket_level_id'], 'ticket_assignments_user_level_unique');

            $table->index(['ticket_section_id', 'active']);
            $table->index(['ticket_level_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_assignments');
    }
};
