<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_status_changes', function (Blueprint $table) {
            // Every move a ticket ever made, and who made it.
            $table->id();

            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();

            // NULL on the creation row, so the audit is complete from birth
            // rather than starting at the first transition.
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);

            // A Fixed->Pending written by /reopen is a different event from the
            // same edge written by /status: it demands a reason, notifies
            // differently, and is what anyone counting "how often does this come
            // back" is actually asking about.
            $table->boolean('is_reopen')->default(false);

            // Required to reopen, optional otherwise.
            $table->text('reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['ticket_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_status_changes');
    }
};
