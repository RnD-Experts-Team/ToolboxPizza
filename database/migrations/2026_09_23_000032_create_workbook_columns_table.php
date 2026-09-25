<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workbook_columns', function (Blueprint $table) {
            // Named `workbook_columns`, not `columns`. The reference used the
            // bare name; it collides with the information_schema notion in
            // tooling and reads as a reserved word in some engines.
            $table->id();

            $table->foreignId('workbook_id')->constrained('workbooks')->cascadeOnDelete();

            $table->string('name', 190);

            // WorkbookColumnType. Decides which workbook_cells slot the value
            // is written to, and therefore how it sorts and filters.
            $table->string('type', 32)->default('text');

            // Select choices only. Written and read whole, never queried into,
            // which is the one case where a JSON column costs nothing.
            $table->json('options')->nullable();

            $table->boolean('required')->default(false);

            // Rewritten from the submitted array index on every whole-list
            // replace, so gaps left by a deletion close themselves.
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index(['workbook_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workbook_columns');
    }
};
