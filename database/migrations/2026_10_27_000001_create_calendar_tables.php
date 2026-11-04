<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The calendar's own events: plain events, tasks and reminders.
 *
 * One base row per thing in `calendar_events`; what only a task or only a
 * reminder needs lives in its own table, one-to-one with the base row
 * (`type` says which one exists):
 *
 *   calendar_events               title, date, time … — everything shared
 *   ├─ calendar_event_store       the stores it shows in (events and tasks)
 *   │   └─ calendar_task_assignments   a task's status in that one store
 *   ├─ calendar_tasks             task settings: proof, requires_review
 *   └─ calendar_reminders         owner, when, what it points at
 *
 * Prefixed `calendar_` so they are never confused with the NATS event tables.
 * Dates and times are UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16)->index(); // event | task | reminder
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->string('category', 40)->nullable(); // free text for now
            $table->string('priority', 10)->default('medium'); // low | medium | high | urgent
            $table->date('date');
            $table->time('start_time')->nullable(); // for a task: the deadline
            $table->time('end_time')->nullable();
            $table->string('link', 255)->nullable(); // e.g. a ticket this is about
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['date', 'type']);
        });

        Schema::create('calendar_event_store', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->unsignedBigInteger('store_id');
            $table->foreign('store_id')->references('id')->on('stores');
            $table->timestamps();

            $table->unique(['calendar_event_id', 'store_id']);
            $table->index('store_id');
        });

        Schema::create('calendar_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_event_id')->unique()->constrained('calendar_events')->cascadeOnDelete();
            $table->string('proof', 16)->default('none'); // none | note
            $table->boolean('requires_review')->default(true);
            $table->timestamps();
        });

        Schema::create('calendar_task_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_event_store_id')->unique()->constrained('calendar_event_store')->cascadeOnDelete();
            $table->string('status', 16)->default('open'); // open | in_progress | submitted | approved | returned | cancelled
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users');
            $table->boolean('submitted_late')->default(false);
            $table->text('completion_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->string('return_reason', 255)->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('calendar_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_event_id')->unique()->constrained('calendar_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users'); // the only person who sees it
            $table->timestamp('remind_at');
            $table->string('target_ref', 120)->nullable(); // a feed event id, e.g. maintenance:tech_visit:812
            $table->string('target_title', 120)->nullable();
            $table->string('target_link', 255)->nullable();
            $table->string('status', 16)->default('scheduled'); // scheduled | sent | done | cancelled
            $table->timestamp('sent_at')->nullable();
            $table->unsignedSmallInteger('snooze_count')->default(0);
            $table->timestamps();

            // The dispatcher's question, every minute: what is due and not sent?
            $table->index(['status', 'remind_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_reminders');
        Schema::dropIfExists('calendar_task_assignments');
        Schema::dropIfExists('calendar_tasks');
        Schema::dropIfExists('calendar_event_store');
        Schema::dropIfExists('calendar_events');
    }
};
