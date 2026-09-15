<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('break_entries', function (Blueprint $table) {
            // One break.
            //
            // Named break_entries, not breaks, because `break` is a PHP reserved
            // word - `class Break` is a fatal parse error, so the model has to
            // be BreakEntry and the table follows it. The public URI is still
            // /breaks.
            //
            // WORK DAY: a break belongs to the work day it STARTED in, where a
            // work day runs cutoff-to-cutoff UTC (see config/toolbox.php). A
            // break that starts 23:00 and ends 07:00 the next morning counts
            // ENTIRELY against the day it started. Splitting it was rejected: it
            // would turn one break into two rows, break the "one open break"
            // invariant, and produce a day summary whose line items no longer
            // match what the user actually did.
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // ALWAYS set. Unlike ticket_issues.issue_id this FK is never null:
            // the counted/excluded answer lives on the catalog row, and a break
            // with no type would have no answer to "does this count against the
            // allowance". "Custom / Other" is therefore a real catalog row that
            // carries requires_custom_label, with its free text in other_label.
            //
            // restrictOnDelete because a type is retired via break_types.active,
            // never deleted out from under existing history.
            $table->foreignId('break_type_id')->constrained('break_types')->restrictOnDelete();
            $table->string('other_label', 120)->nullable();

            // Both UTC, both user-settable: times can be corrected after the
            // fact, and a break the user forgot to time can be entered whole.
            $table->dateTime('started_at');

            // NULL means RUNNING. There is no separate status column, because a
            // status and a nullable end are the same fact, and two
            // representations of one fact drift apart.
            //
            // At most one NULL per user. That is NOT enforced here: MySQL has no
            // partial unique index, and a generated-column index could not
            // express the overlap rule that needs the same lock anyway. Writers
            // serialise on the user's user_break_settings row instead - see
            // BreakWriteService and that table's migration.
            $table->dateTime('ended_at')->nullable();

            // DERIVED from started_at by WorkDayResolver. Stored rather than
            // computed so a day query is one indexed range scan instead of an
            // expression over every row, and RECOMPUTED whenever started_at
            // moves.
            $table->date('work_date');

            // SECONDS, and null while running.
            //
            // Seconds because rounding each break to whole minutes and then
            // summing does not equal the real day total: ten 90-second breaks
            // are 15 real minutes, but 10 if each one floors. All arithmetic -
            // day totals, milestone crossings, overage - is in seconds; minutes
            // are produced once, at presentation.
            $table->unsignedInteger('duration_seconds')->nullable();

            // SNAPSHOT of break_types.counts_toward_limit at write time, not a
            // join. A day summary is a document the user sends their manager;
            // reclassifying Finance next month must not silently rewrite what
            // was already sent. The cost is that fixing a mis-set catalog flag
            // needs a backfill.
            $table->boolean('counts_toward_limit');

            // 'timer'  - started and stopped live through the timer endpoints
            // 'manual' - entered or back-dated by hand
            //
            // ENTRY ORIGIN, not an edit audit trail. There is deliberately no
            // revision table, no edited_at and no edit counter: edits overwrite
            // in place. Do not read this column as "was this changed".
            $table->string('source', 20); // timer|manual

            $table->timestamps();

            $table->index(['user_id', 'work_date']);   // the day breakdown + prune
            $table->index(['user_id', 'ended_at']);    // "is anything running"

            // The overlap guard, which works in ABSOLUTE INSTANTS and never in
            // work_date: a 23:00->07:00 break must block an 02:00 entry that
            // lives on the following work day.
            $table->index(['user_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('break_entries');
    }
};
