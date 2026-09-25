<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Purely additive. role_name has been replicated into this table since it
     * was created but never queried - every existing caller asks only whether a
     * grant covers a store. The workbook visibility clause is the first thing
     * to match ON the role name, and it drives from the user.
     */
    public function up(): void
    {
        Schema::table('user_store_roles', function (Blueprint $table) {
            $table->index(['user_id', 'role_name'], 'user_store_roles_user_role_index');
        });
    }

    public function down(): void
    {
        Schema::table('user_store_roles', function (Blueprint $table) {
            $table->dropIndex('user_store_roles_user_role_index');
        });
    }
};
