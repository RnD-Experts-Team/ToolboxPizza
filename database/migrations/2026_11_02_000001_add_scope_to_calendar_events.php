<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who an event or task is for (`calendar_events.scope`):
 *
 *   custom   the stores chosen when it was made (as before — every existing row)
 *   public   every store that existed when it was made
 *   private  only the user who made it: no stores at all. A private task is
 *            simply done or not, so its state is `calendar_tasks.done_at`.
 *
 * Only `administer calendar` (the super-admin) can make public or private ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->string('scope', 10)->default('custom')->after('priority');
        });

        Schema::table('calendar_tasks', function (Blueprint $table) {
            $table->timestamp('done_at')->nullable()->after('requires_review');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_tasks', function (Blueprint $table) {
            $table->dropColumn('done_at');
        });

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }
};
