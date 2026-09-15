<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_break_settings', function (Blueprint $table) {
            // One row per user, created lazily on first touch from
            // config('toolbox.breaks.default_daily_allowance_minutes').
            //
            // NOT a column on `users`: that table is a NATS replication mirror
            // whose rows are rewritten by updateOrCreate in the auth.v1.user.*
            // handlers, so local domain state parked there is one careless
            // handler edit away from being clobbered.
            //
            // This row is also the LOCK TARGET for the "one open break at a
            // time" invariant — BreakWriteService takes SELECT ... FOR UPDATE on
            // it before looking for an open break, because MySQL has no partial
            // unique index that could express "at most one NULL ended_at per
            // user". That makes the row load-bearing: it must EXIST before the
            // check, which is why the service goes through firstOrCreate first.
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            // Minutes, because that is the unit the user sets ("50", "30").
            // Compared against a seconds total by multiplication, so nothing is
            // lost to rounding.
            $table->unsignedSmallInteger('daily_allowance_minutes');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_break_settings');
    }
};
