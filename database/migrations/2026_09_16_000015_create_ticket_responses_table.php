<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_responses', function (Blueprint $table) {
            // The conversation on a ticket.
            //
            // A FLAT thread, not nested: nothing in the requirement needs
            // replies-to-replies, and a tree that nobody branches is just an
            // extra column that has to be ordered around.
            $table->id();

            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->text('body');

            $table->timestamps();

            // A deleted response's attachments must stay resolvable until
            // attachments:prune reaches them.
            $table->softDeletes();

            // Oldest first, with id as the tiebreaker so two responses written
            // in the same second still have a total order.
            $table->index(['ticket_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_responses');
    }
};
