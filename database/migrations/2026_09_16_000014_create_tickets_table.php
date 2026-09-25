<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();

            // The integer pk, not the code. The code lives on stores and never
            // changes; carrying it here would be a second copy to keep honest.
            // restrictOnDelete is inert in practice because stores soft-delete.
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();

            // Restrict, not cascade: retiring a section must never take its
            // tickets with it. Sections are retired via `active`.
            $table->foreignId('ticket_section_id')->constrained('ticket_sections')->restrictOnDelete();

            $table->string('title', 190);
            $table->text('description');

            // Never null - the reporter's implicit right to read and reply is
            // derived from it. Safe because UserDeletedHandler keeps local rows.
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();

            // STORED, unlike MaintenancePizza's derived ticket status. A Toolbox
            // ticket has no child issues to derive from, and canTransitionTo()
            // needs one authoritative current value to validate against.
            // Deriving it would also mean writing the same rules twice - once in
            // PHP and once in SQL for the filters - and keeping them in step by
            // hand, which is the trap the sibling fell into.
            $table->string('status', 20)->default('pending'); // pending|in_progress|fixed|closed

            // Set once on the first response and never rewritten.
            $table->dateTime('first_responded_at')->nullable();
            // Both cleared on reopen.
            $table->dateTime('fixed_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->dateTime('reopened_at')->nullable();

            // A ticket reopened four times is a different signal from one
            // reopened once. Derivable from the audit table, but denormalised
            // so it can be sorted on.
            $table->unsignedInteger('reopen_count')->default(0);

            // Denormalised from responses, notes and status changes so the
            // default sort is one indexed scan rather than a correlated MAX().
            // ONE owner writes it: TicketService::touchActivity().
            $table->dateTime('last_activity_at');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'status', 'id']);
            $table->index(['ticket_section_id', 'status']);
            $table->index(['reported_by', 'status']);
            $table->index(['status', 'last_activity_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
