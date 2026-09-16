<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_levels', function (Blueprint $table) {
            // A grouping over sections, so someone can be made responsible for
            // "all of Hiring" rather than for each hiring section by hand.
            //
            // Levels NEST, and a section may sit under SEVERAL levels at once -
            // so this is a directed graph, not a tree. Assignment to a level
            // reaches every section beneath it, however deep.
            $table->id();

            // Self-referencing FK. Legal inside create() on both drivers.
            //
            // nullOnDelete promotes orphans to roots rather than cascading a
            // whole subtree out of existence - losing one level should not
            // silently unassign everyone below it.
            //
            // THE DATABASE CANNOT EXPRESS "no cycles". TicketLevelService
            // refuses a parent that is the node itself or any descendant, and
            // TicketLevelGraph's walks carry a visited set as a seatbelt for a
            // cycle that arrives any other way (a seeder, a manual UPDATE, a
            // restored backup). Neither is redundant: one prevents, one contains.
            $table->foreignId('parent_id')->nullable()->constrained('ticket_levels')->nullOnDelete();

            // Stable machine key, so a seeder or a cross-service reference
            // survives someone rewording `name`.
            $table->string('key', 64)->unique();
            $table->string('name', 190);
            $table->text('description')->nullable();

            $table->unsignedInteger('display_order')->default(0);

            // Retirement is a flag, never a delete - assignments point here.
            //
            // An inactive level is absent from the graph, which means it SEVERS
            // the chain: Section -> L3 -> L2(inactive) -> L1 reaches L3 only,
            // and everyone assigned at L1 stops receiving. That is the honest
            // reading of "inactive", but it is a footgun for an admin who
            // thinks they are only hiding a row from a picker.
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->index(['parent_id', 'display_order']);
            $table->index(['active', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_levels');
    }
};
