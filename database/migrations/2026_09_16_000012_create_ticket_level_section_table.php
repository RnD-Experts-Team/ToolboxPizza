<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_level_section', function (Blueprint $table) {
            // Which sections sit under which levels.
            //
            // Many-to-many on purpose: a section may belong to SEVERAL levels at
            // once, so the same section can be reachable by two different groups
            // of people without duplicating it.
            $table->id();

            $table->foreignId('ticket_level_id')->constrained('ticket_levels')->cascadeOnDelete();
            $table->foreignId('ticket_section_id')->constrained('ticket_sections')->cascadeOnDelete();

            $table->timestamps();

            // Many levels, yes; the same one twice, no.
            $table->unique(['ticket_level_id', 'ticket_section_id'], 'ticket_level_section_unique');

            // The resolution hot path reads section -> levels, not the reverse.
            $table->index('ticket_section_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_level_section');
    }
};
