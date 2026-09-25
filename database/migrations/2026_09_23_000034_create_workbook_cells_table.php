<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workbook_cells', function (Blueprint $table) {
            $table->id();

            $table->foreignId('row_id')->constrained('workbook_rows')->cascadeOnDelete();
            $table->foreignId('column_id')->constrained('workbook_columns')->cascadeOnDelete();

            // ONE of the typed slots is written according to the column's type,
            // and value_text is ALWAYS written as well, holding the display
            // string. The duplication buys a single-clause cross-column search
            // (one LIKE on value_text) instead of a four-way OR, and it means
            // a column whose type is changed later still renders while its
            // values are re-slotted.
            //
            // Sorting and filtering use the TYPED slot, so 2 comes before 10
            // and a date range is a range.
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 20, 6)->nullable();
            $table->dateTime('value_date')->nullable();
            $table->boolean('value_bool')->nullable();

            $table->timestamps();

            // One cell per (row, column). The row writer relies on this to make
            // an upsert safe.
            $table->unique(['row_id', 'column_id'], 'workbook_cells_row_column_unique');

            // Every filter and sort is "this column, that value". Without these
            // the grid is a full table scan per filter - the reference has no
            // index here at all.
            $table->index(['column_id', 'value_number']);
            $table->index(['column_id', 'value_date']);
        });

        // MySQL cannot index a TEXT column without a prefix length, and the
        // Blueprint API has no syntax for one. SQLite indexes the whole column
        // happily and rejects the prefix syntax, so it is skipped there - the
        // test database is small enough that it changes nothing.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('CREATE INDEX workbook_cells_col_text ON workbook_cells (column_id, value_text(191))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workbook_cells');
    }
};
