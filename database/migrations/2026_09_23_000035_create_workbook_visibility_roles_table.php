<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workbook_visibility_roles', function (Blueprint $table) {
            // The role names a store_role_view / store_role_edit tag names.
            //
            // WHY A TABLE AND NOT A JSON COLUMN ON THE RESOURCE: the list
            // endpoints have to match these against the replicated
            // user_store_roles INSIDE the query, so that filtering happens
            // before pagination and page counts stay honest. JSON containment
            // is spelled differently in MySQL and SQLite - and the tests run on
            // SQLite - so a JSON column would have forced a post-pagination
            // filter in PHP, which returns short pages. A child table joins.
            $table->id();

            // WorkbookVisibleType: 'folder' | 'workbook' | 'row'.
            //
            // Not $table->morphs(): the join that reads this lives in
            // hand-written SQL, and a PHP class name has no business in a query
            // - nor should renaming a model invalidate stored rows.
            $table->string('visible_type', 32);
            $table->unsignedBigInteger('visible_id');

            // Free text matching pizzasys' role names. There is no local roles
            // table to constrain against: roles are created dynamically over
            // there and only ever arrive here denormalised onto a grant. A typo
            // therefore matches nobody, silently - WorkbookService
            // normalises what it can and the UI is asked to show a match count.
            $table->string('role_name', 190);

            $table->timestamps();

            // NOTHING CASCADES ON A POLYMORPHIC KEY. The write services delete
            // these rows by hand when a folder, workbook or row goes.
            $table->unique(['visible_type', 'visible_id', 'role_name'], 'workbook_visibility_roles_unique');
            $table->index(['visible_type', 'visible_id']);
            $table->index(['role_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workbook_visibility_roles');
    }
};
