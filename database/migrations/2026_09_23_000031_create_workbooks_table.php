<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workbooks', function (Blueprint $table) {
            // One user-defined table. The columns live in workbook_columns and
            // the data in workbook_rows + workbook_cells.
            $table->id();

            // Every workbook sits in a folder - there are no root workbooks,
            // because the folder is half of the visibility chain.
            $table->foreignId('workbook_folder_id')
                ->constrained('workbook_folders')->cascadeOnDelete();

            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name', 190);
            $table->text('description')->nullable();
            $table->string('visibility', 32)->default('owner_only');

            $table->timestamps();

            $table->index(['workbook_folder_id']);
            // Drives the store_* arm of the visibility clause.
            $table->index(['store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workbooks');
    }
};
