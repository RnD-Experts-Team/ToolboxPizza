<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_sections', function (Blueprint $table) {
            // Where a ticket came from, and therefore who should get it.
            $table->id();

            // THE CONTRACT WITH THE DASHBOARD. Boxes 3, 4 and 7 on the main
            // dashboard all POST the same key; screens, the hiring page and
            // hiring's own tickets each have their own. The create endpoint
            // takes this key and never an id, so the frontend has one thing to
            // hardcode and renaming the display name breaks nothing.
            $table->string('key', 64)->unique();

            $table->string('name', 190);
            $table->text('description')->nullable();

            $table->unsignedInteger('display_order')->default(0);

            // A retired section keeps resolving for the tickets that already
            // point at it; only NEW tickets are refused. Same split as
            // BreakService::resolveType($forNewEntry) - history must keep
            // rendering after the catalog moves on.
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->index(['active', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_sections');
    }
};
