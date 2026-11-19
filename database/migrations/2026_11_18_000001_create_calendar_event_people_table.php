<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `scope = people`: an event or task for chosen people (store managers), not
 * for stores. One row per person. For a task the row is that person's own state,
 * exactly like calendar_task_assignments is a store's; for a plain event
 * `status` stays null — the row only says who sees it.
 *
 * New table only: no existing table or row is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_event_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');

            // tasks only: open | in_progress | submitted | approved | returned | cancelled
            $table->string('status', 16)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->boolean('submitted_late')->default(false);
            $table->text('completion_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->string('return_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['calendar_event_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_event_people');
    }
};
