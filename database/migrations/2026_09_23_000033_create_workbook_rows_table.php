<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workbook_rows', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workbook_id')->constrained('workbooks')->cascadeOnDelete();

            // THE STORE THE ROW WAS ADDED AT, which is not necessarily the
            // workbook's. That is the point: an all_stores_edit workbook whose
            // rows are each store_view lets every store contribute to one
            // shared table while seeing only its own lines. The reference
            // dropped this column; it is deliberately back.
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('visibility', 32)->default('owner_only');

            // The reference had no row ordering at all - rows came back in
            // created_at order and that was the only option.
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index(['workbook_id', 'position']);
            $table->index(['workbook_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workbook_rows');
    }
};
