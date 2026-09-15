<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('break_milestones', function (Blueprint $table) {
            // A threshold in MINUTES USED that this user wants flagged — 20, 40,
            // 50. Not a percentage and not a countdown: minutes consumed was the
            // requirement, so a user whose allowance changes keeps the same
            // trigger points until they say otherwise.
            //
            // A child table rather than a JSON column on user_break_settings, so
            // the evaluator selects the thresholds it needs instead of filtering
            // the whole list in PHP on every read, and so one bad write cannot
            // corrupt the entire set.
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('threshold_minutes');
            $table->timestamps();

            // Deleting IS deactivation; there is no `active` flag. Firings key
            // on the integer threshold rather than on this row's id, so deleting
            // and re-adding "20" mid-day does NOT let it fire a second time that
            // day — see break_milestone_firings.
            $table->unique(['user_id', 'threshold_minutes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('break_milestones');
    }
};
