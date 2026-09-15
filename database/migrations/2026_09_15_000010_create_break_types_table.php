<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('break_types', function (Blueprint $table) {
            // The catalog of break kinds. A TABLE rather than an enum, because
            // counts_toward_limit is data the business edits: moving Finance in
            // or out of the excluded block should be a row update, not a deploy.
            //
            // The two blocks the product names — the ordinary types, and
            // "Special breaks - excluded from limit" below the separator — are
            // that one boolean plus a sort order, not two tables.
            $table->id();

            // Stable machine key. `name` is display copy and may be reworded at
            // any time; seeders, tests and clients branch on the slug.
            $table->string('slug', 60)->unique();
            $table->string('name', 120);

            // Presentation grouping only. The arithmetic is counts_toward_limit
            // below — never this column. See App\Enums\BreakTypeGroup.
            $table->string('group', 20)->default('regular'); // regular|special

            $table->boolean('counts_toward_limit')->default(true);

            // True for exactly one seeded row ('other'). When set, a break of
            // this type MUST carry break_entries.other_label, and a break of any
            // other type MUST NOT. Same catalog-or-free-text idea as
            // ticket_issues.issue_id / other_title, except the flag lives on the
            // catalog row instead of being inferred from a null foreign key —
            // break_entries.break_type_id is never null, because a typeless
            // break would have no answer to "does this count".
            $table->boolean('requires_custom_label')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);

            // Retiring a type is `active = false`, not a delete: 30 days of
            // history point at these rows and must keep rendering their label.
            $table->boolean('active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('break_types');
    }
};
