<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_participants', function (Blueprint $table) {
            // People explicitly added to ONE ticket, on top of whoever the
            // section/level resolution already reaches.
            //
            // This is the escape hatch for "this one needs Dana, even though
            // Dana doesn't own hiring" - and for looping in someone who should
            // follow along without being able to act.
            $table->id();

            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // reader    - can read
            // responder - can read and reply (the reporter's powers)
            // assignee  - full assignee powers on this ticket only
            $table->string('role', 20);

            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // ONE row per user per ticket. Re-adding changes the role rather
            // than stacking a second grant - uniqueness on (ticket,user,role)
            // would make a downgrade impossible to express.
            $table->unique(['ticket_id', 'user_id'], 'ticket_participants_unique');

            // "Tickets I'm on", for the cross-store inbox.
            $table->index(['user_id', 'ticket_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_participants');
    }
};
