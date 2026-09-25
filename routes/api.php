<?php

use App\Http\Controllers\Api\V1\BreakController;
use App\Http\Controllers\Api\V1\BreakSettingController;
use App\Http\Controllers\Api\V1\TicketCatalogController;
use App\Http\Controllers\Api\V1\TicketController;
use App\Http\Controllers\Api\V1\WorkbookController;
use App\Http\Controllers\Api\V1\WorkbookRowController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ToolboxPizza API (v1)
|--------------------------------------------------------------------------
| Everything sits behind 'auth.token.store', which verifies the caller's
| bearer token against pizzasys and then Auth::login()s the locally mirrored
| user. There is no local login and no per-route authorization here: pizzasys
| answers ext.authorized for the exact method + path before anything runs.
|
| House conventions in force: every route is named, and UPDATES ARE POST - not
| PUT/PATCH - across these services.
*/
Route::prefix('v1')->middleware('auth.token.store')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | The break catalog. Read-only: types are seeded, and retiring one is a flag
    | on the row, never a delete, because 30 days of history point at it.
    |--------------------------------------------------------------------------
    */
    Route::get('break-types', [BreakSettingController::class, 'types'])->name('api.v1.break-types.index');

    /*
    |--------------------------------------------------------------------------
    | Each user's own allowance and milestone thresholds. There is no manager in
    | this service - the person taking the breaks sets the budget they are
    | measured against. Reading settings CREATES the row on first touch, so a
    | client never has to run a setup step.
    |--------------------------------------------------------------------------
    */
    Route::get('break-settings', [BreakSettingController::class, 'show'])->name('api.v1.break-settings.show');
    Route::post('break-settings', [BreakSettingController::class, 'update'])->name('api.v1.break-settings.update');

    Route::get('break-milestones', [BreakSettingController::class, 'milestones'])->name('api.v1.break-milestones.index');
    Route::post('break-milestones', [BreakSettingController::class, 'replaceMilestones'])->name('api.v1.break-milestones.replace');
    Route::delete('break-milestones/{milestoneId}', [BreakSettingController::class, 'destroyMilestone'])
        ->whereNumber('milestoneId')
        ->name('api.v1.break-milestones.destroy');

    /*
    |--------------------------------------------------------------------------
    | Breaks.
    |
    | The literal segments are declared BEFORE breaks/{breakId}, and the
    | parameter is constrained to digits, so 'active' can never be swallowed as
    | an id. {breakId} is a plain integer rather than a bound model because
    | ownership is applied by BreakQueryService::findOwn() - binding would resolve the row
    | before that filter could.
    |--------------------------------------------------------------------------
    */
    Route::get('breaks/active', [BreakController::class, 'active'])->name('api.v1.breaks.active');
    Route::post('breaks/start', [BreakController::class, 'start'])->name('api.v1.breaks.start');

    // The day breakdown, and the same payload plus the paste-ready `text`.
    // Two endpoints rather than one ?format= because the day view is the one a
    // client polls, and it should not render a document on every tick.
    Route::get('breaks/day', [BreakController::class, 'day'])->name('api.v1.breaks.day');
    Route::get('breaks/day/export', [BreakController::class, 'export'])->name('api.v1.breaks.day.export');

    Route::get('breaks', [BreakController::class, 'index'])->name('api.v1.breaks.index');
    // The forgot-to-time entry: both ends supplied by hand.
    Route::post('breaks', [BreakController::class, 'store'])->name('api.v1.breaks.store');

    Route::get('breaks/{breakId}', [BreakController::class, 'show'])
        ->whereNumber('breakId')->name('api.v1.breaks.show');
    Route::post('breaks/{breakId}', [BreakController::class, 'update'])
        ->whereNumber('breakId')->name('api.v1.breaks.update');
    Route::delete('breaks/{breakId}', [BreakController::class, 'destroy'])
        ->whereNumber('breakId')->name('api.v1.breaks.destroy');

    Route::post('breaks/{breakId}/stop', [BreakController::class, 'stop'])
        ->whereNumber('breakId')->name('api.v1.breaks.stop');

    // Notes may be added while the break runs or long after it ended.
    Route::post('breaks/{breakId}/notes', [BreakController::class, 'storeNote'])
        ->whereNumber('breakId')->name('api.v1.breaks.notes.store');

    /*
    |--------------------------------------------------------------------------
    | Tickets - the admin catalog.
    |
    | Sections are what the dashboard tags itself with: boxes 3, 4 and 7 all
    | send the same `key`. Levels group sections and NEST, so assigning someone
    | a level makes them responsible for everything beneath it.
    |
    | Retiring is `active = false`, never a delete - tickets point at these rows
    | and must keep rendering long after the catalog moves on. An assignment IS
    | deleted, because it is configuration rather than history.
    |--------------------------------------------------------------------------
    */
    Route::get('ticket-sections', [TicketCatalogController::class, 'sections'])->name('api.v1.ticket-sections.index');
    Route::post('ticket-sections', [TicketCatalogController::class, 'storeSection'])->name('api.v1.ticket-sections.store');
    Route::post('ticket-sections/{sectionId}', [TicketCatalogController::class, 'updateSection'])
        ->whereNumber('sectionId')->name('api.v1.ticket-sections.update');
    Route::delete('ticket-sections/{sectionId}', [TicketCatalogController::class, 'destroySection'])
        ->whereNumber('sectionId')->name('api.v1.ticket-sections.destroy');

    Route::get('ticket-levels', [TicketCatalogController::class, 'levels'])->name('api.v1.ticket-levels.index');
    Route::post('ticket-levels', [TicketCatalogController::class, 'storeLevel'])->name('api.v1.ticket-levels.store');
    // Declared before the {levelId} catch-all so 'sections' is never read as an id.
    Route::post('ticket-levels/{levelId}/sections', [TicketCatalogController::class, 'syncLevelSections'])
        ->whereNumber('levelId')->name('api.v1.ticket-levels.sections.sync');
    Route::post('ticket-levels/{levelId}', [TicketCatalogController::class, 'updateLevel'])
        ->whereNumber('levelId')->name('api.v1.ticket-levels.update');
    Route::delete('ticket-levels/{levelId}', [TicketCatalogController::class, 'destroyLevel'])
        ->whereNumber('levelId')->name('api.v1.ticket-levels.destroy');

    Route::get('ticket-assignments', [TicketCatalogController::class, 'assignments'])->name('api.v1.ticket-assignments.index');
    Route::post('ticket-assignments', [TicketCatalogController::class, 'storeAssignment'])->name('api.v1.ticket-assignments.store');
    Route::post('ticket-assignments/{assignmentId}', [TicketCatalogController::class, 'updateAssignment'])
        ->whereNumber('assignmentId')->name('api.v1.ticket-assignments.update');
    Route::delete('ticket-assignments/{assignmentId}', [TicketCatalogController::class, 'destroyAssignment'])
        ->whereNumber('assignmentId')->name('api.v1.ticket-assignments.destroy');

    /*
    |--------------------------------------------------------------------------
    | Tickets.
    |
    | {storeId} is the store CODE ("03795-00001") as a raw string, resolved by
    | Store::resolveByNumber() - not route-model binding, so the value the auth middleware
    | forwards to pizzasys is the same code that appears in the URL.
    |
    | A ticket from another store is a 404 under this store's path, and a ticket
    | the caller has no relationship to is ALSO a 404 rather than a 403 - a 403
    | would confirm the id exists. Every other ability 403s, because by then the
    | caller can already see the thing.
    |--------------------------------------------------------------------------
    */
    // The cross-store inbox. Declared before the store-scoped group so it is
    // never shadowed. pizzasys makes no per-store decision here - there is no
    // store in the path - so TicketQueryService scopes it to the caller itself.
    Route::get('tickets', [TicketController::class, 'inbox'])->name('api.v1.tickets.inbox');

    Route::prefix('stores/{storeId}')->group(function () {
        Route::get('tickets', [TicketController::class, 'index'])->name('api.v1.stores.tickets.index');
        Route::post('tickets', [TicketController::class, 'store'])->name('api.v1.stores.tickets.store');

        Route::get('tickets/{ticketId}', [TicketController::class, 'show'])
            ->whereNumber('ticketId')->name('api.v1.stores.tickets.show');
        Route::post('tickets/{ticketId}', [TicketController::class, 'update'])
            ->whereNumber('ticketId')->name('api.v1.stores.tickets.update');

        Route::post('tickets/{ticketId}/status', [TicketController::class, 'changeStatus'])
            ->whereNumber('ticketId')->name('api.v1.stores.tickets.status');
        Route::post('tickets/{ticketId}/reopen', [TicketController::class, 'reopen'])
            ->whereNumber('ticketId')->name('api.v1.stores.tickets.reopen');

        Route::post('tickets/{ticketId}/responses', [TicketController::class, 'respond'])
            ->whereNumber('ticketId')->name('api.v1.stores.tickets.responses.store');
        Route::post('tickets/{ticketId}/notes', [TicketController::class, 'storeNote'])
            ->whereNumber('ticketId')->name('api.v1.stores.tickets.notes.store');
        Route::post('tickets/{ticketId}/attachments', [TicketController::class, 'storeAttachments'])
            ->whereNumber('ticketId')->name('api.v1.stores.tickets.attachments.store');

        Route::get('tickets/{ticketId}/participants', [TicketController::class, 'participants'])
            ->whereNumber('ticketId')->name('api.v1.stores.tickets.participants.index');
        Route::post('tickets/{ticketId}/participants', [TicketController::class, 'addParticipant'])
            ->whereNumber('ticketId')->name('api.v1.stores.tickets.participants.store');
        Route::delete('tickets/{ticketId}/participants/{userId}', [TicketController::class, 'removeParticipant'])
            ->whereNumber('ticketId')->whereNumber('userId')->name('api.v1.stores.tickets.participants.destroy');

        /*
        |----------------------------------------------------------------------
        | Workbook creates. The store in the path is where the thing is created
        | - it becomes the resource's own store, and it is what the store_* tags
        | resolve against. A row's store need NOT be its workbook's: that is
        | what lets every store add lines to one shared workbook and see only
        | its own.
        |----------------------------------------------------------------------
        */
        Route::post('workbook-folders', [WorkbookController::class, 'storeFolder'])
            ->name('api.v1.stores.workbook-folders.store');
        Route::post('workbook-folders/{folderId}/workbooks', [WorkbookController::class, 'store'])
            ->whereNumber('folderId')->name('api.v1.stores.workbooks.store');
        Route::post('workbooks/{workbookId}/rows', [WorkbookRowController::class, 'store'])
            ->whereNumber('workbookId')->name('api.v1.stores.workbooks.rows.store');

        // The resolution made observable: who this reaches, and why.
        Route::get('tickets/{ticketId}/recipients', [TicketController::class, 'recipients'])
            ->whereNumber('ticketId')->name('api.v1.stores.tickets.recipients');
    })->where('storeId', '[A-Za-z0-9._-]+');

    /*
    |--------------------------------------------------------------------------
    | Workbooks: nested folders holding user-defined tables.
    |
    | READS ARE NOT STORE-SCOPED and creates are, which looks inconsistent until
    | you look at what the tags mean. A folder tagged all_stores_view is visible
    | from every store at once, so /stores/{storeId}/workbook-folders would
    | either repeat it under each store or hide it - the visibility clause is
    | what scopes a read, and it is the only thing that can. A CREATE, though,
    | has to record which store it happened at, and that is a question pizzasys
    | can gate.
    |
    | Mutations of an EXISTING resource are global for the same reason: an
    | all_stores_edit workbook is meant to be editable by somebody at another
    | store, and a store-prefixed path would name the OWNING store and lock out
    | exactly the person the tag exists to admit. WorkbookAccessService is
    | the per-resource authority there. That is the same single-clause exposure
    | the README already records for GET /tickets.
    |
    | Ids are plain integers rather than bound models because visibility is
    | applied in WorkbookAccessService - binding would resolve the row
    | before the check could turn it into a 404.
    |--------------------------------------------------------------------------
    */

    // The labelled tag and column-type catalogues, so the dashboard does not
    // hard-code seven strings and their wording.
    Route::get('workbook-options', [WorkbookController::class, 'options'])
        ->name('api.v1.workbook-options.index');

    Route::get('workbook-folders', [WorkbookController::class, 'folders'])
        ->name('api.v1.workbook-folders.index');
    Route::get('workbook-folders/{folderId}', [WorkbookController::class, 'showFolder'])
        ->whereNumber('folderId')->name('api.v1.workbook-folders.show');
    Route::post('workbook-folders/{folderId}', [WorkbookController::class, 'updateFolder'])
        ->whereNumber('folderId')->name('api.v1.workbook-folders.update');
    Route::post('workbook-folders/{folderId}/visibility', [WorkbookController::class, 'retagFolder'])
        ->whereNumber('folderId')->name('api.v1.workbook-folders.visibility.update');
    // Takes the whole subtree. Refuses a populated folder without ?force=true,
    // and says how much is inside so the UI can put a number in the prompt.
    Route::delete('workbook-folders/{folderId}', [WorkbookController::class, 'destroyFolder'])
        ->whereNumber('folderId')->name('api.v1.workbook-folders.destroy');

    Route::get('workbook-folders/{folderId}/workbooks', [WorkbookController::class, 'index'])
        ->whereNumber('folderId')->name('api.v1.workbooks.index');

    Route::get('workbooks/{workbookId}', [WorkbookController::class, 'show'])
        ->whereNumber('workbookId')->name('api.v1.workbooks.show');
    Route::post('workbooks/{workbookId}', [WorkbookController::class, 'update'])
        ->whereNumber('workbookId')->name('api.v1.workbooks.update');
    Route::post('workbooks/{workbookId}/visibility', [WorkbookController::class, 'retag'])
        ->whereNumber('workbookId')->name('api.v1.workbooks.visibility.update');
    Route::delete('workbooks/{workbookId}', [WorkbookController::class, 'destroy'])
        ->whereNumber('workbookId')->name('api.v1.workbooks.destroy');

    // Whole-list replace, mirroring ticket-levels/{id}/sections: the submitted
    // order IS the display order, and an omitted column is deleted.
    Route::get('workbooks/{workbookId}/columns', [WorkbookController::class, 'columns'])
        ->whereNumber('workbookId')->name('api.v1.workbooks.columns.index');
    Route::post('workbooks/{workbookId}/columns', [WorkbookController::class, 'replaceColumns'])
        ->whereNumber('workbookId')->name('api.v1.workbooks.columns.replace');

    // The literal segment is declared BEFORE rows/{rowId} so 'reorder' can
    // never be swallowed as an id.
    Route::get('workbooks/{workbookId}/rows', [WorkbookRowController::class, 'index'])
        ->whereNumber('workbookId')->name('api.v1.workbooks.rows.index');
    Route::post('workbooks/{workbookId}/rows/reorder', [WorkbookRowController::class, 'reorder'])
        ->whereNumber('workbookId')->name('api.v1.workbooks.rows.reorder');
    Route::get('workbooks/{workbookId}/rows/{rowId}', [WorkbookRowController::class, 'show'])
        ->whereNumber('workbookId')->whereNumber('rowId')->name('api.v1.workbooks.rows.show');
    Route::post('workbooks/{workbookId}/rows/{rowId}', [WorkbookRowController::class, 'update'])
        ->whereNumber('workbookId')->whereNumber('rowId')->name('api.v1.workbooks.rows.update');
    Route::post('workbooks/{workbookId}/rows/{rowId}/visibility', [WorkbookRowController::class, 'retag'])
        ->whereNumber('workbookId')->whereNumber('rowId')->name('api.v1.workbooks.rows.visibility.update');
    Route::delete('workbooks/{workbookId}/rows/{rowId}', [WorkbookRowController::class, 'destroy'])
        ->whereNumber('workbookId')->whereNumber('rowId')->name('api.v1.workbooks.rows.destroy');
});
