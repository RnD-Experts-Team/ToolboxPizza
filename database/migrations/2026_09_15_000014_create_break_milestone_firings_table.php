<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('break_milestone_firings', function (Blueprint $table) {
            // Which thresholds have already fired, for whom, on which work day.
            //
            // The UNIQUE at the bottom IS the "at most once per work day"
            // guarantee, and it lives in the database on purpose: evaluation
            // runs on read paths, so two concurrent polls of the same running
            // break WILL compute the same crossing. PHP cannot arbitrate that;
            // the index can. The evaluator uses insertOrIgnore and treats "a row
            // was actually inserted" as "this is the one that fired" - only that
            // emits an event.
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('work_date');

            // 'milestone' - one of the user's chosen thresholds
            // 'allowance' - the daily allowance itself was exceeded
            //
            // Two kinds because a user may set a milestone AT their allowance
            // (50 against a 50-minute budget) and those are two different things
            // to be told. See App\Enums\MilestoneKind.
            $table->string('kind', 20); // milestone|allowance

            // The integer, not a break_milestones.id: deleting and re-adding a
            // threshold mid-day must not re-arm it for that day.
            $table->unsignedSmallInteger('threshold_minutes');

            // The instant the threshold was ACTUALLY crossed, computed from the
            // entries as started_at + (threshold - counted_before). Exact even
            // when the crossing happened in the middle of a break.
            $table->dateTime('crossed_at');

            // The instant evaluation NOTICED. Later than crossed_at whenever
            // nobody asked for a while - a stateless API has no in-process
            // timer, so a threshold crossed while the tab was closed is only
            // recorded at the next observation. Two columns because the report
            // must show what was true, not when we got round to looking.
            $table->dateTime('noticed_at');

            $table->unsignedInteger('counted_seconds_at_cross');

            // The outbox row emitted for this firing, when notifications are on.
            // NULL means nothing was ever sent, which is what makes a firing
            // reapable if a later edit removes the time that justified it. A
            // firing that WAS emitted is never reaped: the user has already been
            // told, and a notification cannot be unsent.
            $table->ulid('outbox_event_id')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'work_date', 'kind', 'threshold_minutes'], 'break_milestone_firings_unique');
            $table->index(['user_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('break_milestone_firings');
    }
};
