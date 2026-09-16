<?php

use App\Http\Controllers\Api\V1\BreakController;
use App\Http\Controllers\Api\V1\BreakDayController;
use App\Http\Controllers\Api\V1\BreakMilestoneController;
use App\Http\Controllers\Api\V1\BreakNoteController;
use App\Http\Controllers\Api\V1\BreakSettingController;
use App\Http\Controllers\Api\V1\BreakTimerController;
use App\Http\Controllers\Api\V1\BreakTypeController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\TicketAssignmentController;
use App\Http\Controllers\Api\V1\TicketAttachmentController;
use App\Http\Controllers\Api\V1\TicketController;
use App\Http\Controllers\Api\V1\TicketLevelController;
use App\Http\Controllers\Api\V1\TicketNoteController;
use App\Http\Controllers\Api\V1\TicketParticipantController;
use App\Http\Controllers\Api\V1\TicketResponseController;
use App\Http\Controllers\Api\V1\TicketSectionController;
use App\Http\Controllers\Api\V1\TicketStatusController;
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
    Route::get('health', HealthController::class)->name('api.v1.health');

    /*
    |--------------------------------------------------------------------------
    | The break catalog. Read-only: types are seeded, and retiring one is a flag
    | on the row, never a delete, because 30 days of history point at it.
    |--------------------------------------------------------------------------
    */
    Route::get('break-types', [BreakTypeController::class, 'index'])->name('api.v1.break-types.index');

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

    Route::get('break-milestones', [BreakMilestoneController::class, 'index'])->name('api.v1.break-milestones.index');
    Route::post('break-milestones', [BreakMilestoneController::class, 'replace'])->name('api.v1.break-milestones.replace');
    Route::delete('break-milestones/{milestoneId}', [BreakMilestoneController::class, 'destroy'])
        ->whereNumber('milestoneId')
        ->name('api.v1.break-milestones.destroy');

    /*
    |--------------------------------------------------------------------------
    | Breaks.
    |
    | The literal segments are declared BEFORE breaks/{breakId}, and the
    | parameter is constrained to digits, so 'active' can never be swallowed as
    | an id. {breakId} is a plain integer rather than a bound model because
    | ownership is applied in ResolvesOwnBreak - binding would resolve the row
    | before that filter could.
    |--------------------------------------------------------------------------
    */
    Route::get('breaks/active', [BreakTimerController::class, 'active'])->name('api.v1.breaks.active');
    Route::post('breaks/start', [BreakTimerController::class, 'start'])->name('api.v1.breaks.start');

    // The day breakdown, and the same payload plus the paste-ready `text`.
    // Two endpoints rather than one ?format= because the day view is the one a
    // client polls, and it should not render a document on every tick.
    Route::get('breaks/day', [BreakDayController::class, 'show'])->name('api.v1.breaks.day');
    Route::get('breaks/day/export', [BreakDayController::class, 'export'])->name('api.v1.breaks.day.export');

    Route::get('breaks', [BreakController::class, 'index'])->name('api.v1.breaks.index');
    // The forgot-to-time entry: both ends supplied by hand.
    Route::post('breaks', [BreakController::class, 'store'])->name('api.v1.breaks.store');

    Route::get('breaks/{breakId}', [BreakController::class, 'show'])
        ->whereNumber('breakId')->name('api.v1.breaks.show');
    Route::post('breaks/{breakId}', [BreakController::class, 'update'])
        ->whereNumber('breakId')->name('api.v1.breaks.update');
    Route::delete('breaks/{breakId}', [BreakController::class, 'destroy'])
        ->whereNumber('breakId')->name('api.v1.breaks.destroy');

    Route::post('breaks/{breakId}/stop', [BreakTimerController::class, 'stop'])
        ->whereNumber('breakId')->name('api.v1.breaks.stop');

    // Notes may be added while the break runs or long after it ended.
    Route::post('breaks/{breakId}/notes', [BreakNoteController::class, 'store'])
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
    Route::get('ticket-sections', [TicketSectionController::class, 'index'])->name('api.v1.ticket-sections.index');
    Route::post('ticket-sections', [TicketSectionController::class, 'store'])->name('api.v1.ticket-sections.store');
    Route::post('ticket-sections/{sectionId}', [TicketSectionController::class, 'update'])
        ->whereNumber('sectionId')->name('api.v1.ticket-sections.update');
    Route::delete('ticket-sections/{sectionId}', [TicketSectionController::class, 'destroy'])
        ->whereNumber('sectionId')->name('api.v1.ticket-sections.destroy');

    Route::get('ticket-levels', [TicketLevelController::class, 'index'])->name('api.v1.ticket-levels.index');
    Route::post('ticket-levels', [TicketLevelController::class, 'store'])->name('api.v1.ticket-levels.store');
    // Declared before the {levelId} catch-all so 'sections' is never read as an id.
    Route::post('ticket-levels/{levelId}/sections', [TicketLevelController::class, 'syncSections'])
        ->whereNumber('levelId')->name('api.v1.ticket-levels.sections.sync');
    Route::post('ticket-levels/{levelId}', [TicketLevelController::class, 'update'])
        ->whereNumber('levelId')->name('api.v1.ticket-levels.update');
    Route::delete('ticket-levels/{levelId}', [TicketLevelController::class, 'destroy'])
        ->whereNumber('levelId')->name('api.v1.ticket-levels.destroy');

    Route::get('ticket-assignments', [TicketAssignmentController::class, 'index'])->name('api.v1.ticket-assignments.index');
    Route::post('ticket-assignments', [TicketAssignmentController::class, 'store'])->name('api.v1.ticket-assignments.store');
    Route::post('ticket-assignments/{assignmentId}', [TicketAssignmentController::class, 'update'])
        ->whereNumber('assignmentId')->name('api.v1.ticket-assignments.update');
    Route::delete('ticket-assignments/{assignmentId}', [TicketAssignmentController::class, 'destroy'])
        ->whereNumber('assignmentId')->name('api.v1.ticket-assignments.destroy');

    /*
    |--------------------------------------------------------------------------
    | Tickets.
    |
    | {storeId} is the store CODE ("03795-00001") as a raw string, resolved by
    | ResolvesStore - not route-model binding, so the value the auth middleware
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

        Route::post('tickets/{ticketId}/status', [TicketStatusController::class, 'update'])
            ->whereNumber('ticketId')->name('api.v1.stores.tickets.status');
        Route::post('tickets/{ticketId}/reopen', [TicketStatusController::class, 'reopen'])
            ->whereNumber('ticketId')->name('api.v1.stores.tickets.reopen');

        Route::post('tickets/{ticketId}/responses', [TicketResponseController::class, 'store'])
            ->whereNumber('ticketId')->name('api.v1.stores.tickets.responses.store');
        Route::post('tickets/{ticketId}/notes', [TicketNoteController::class, 'store'])
            ->whereNumber('ticketId')->name('api.v1.stores.tickets.notes.store');
        Route::post('tickets/{ticketId}/attachments', [TicketAttachmentController::class, 'store'])
            ->whereNumber('ticketId')->name('api.v1.stores.tickets.attachments.store');

        Route::get('tickets/{ticketId}/participants', [TicketParticipantController::class, 'index'])
            ->whereNumber('ticketId')->name('api.v1.stores.tickets.participants.index');
        Route::post('tickets/{ticketId}/participants', [TicketParticipantController::class, 'store'])
            ->whereNumber('ticketId')->name('api.v1.stores.tickets.participants.store');
        Route::delete('tickets/{ticketId}/participants/{userId}', [TicketParticipantController::class, 'destroy'])
            ->whereNumber('ticketId')->whereNumber('userId')->name('api.v1.stores.tickets.participants.destroy');

        // The resolution made observable: who this reaches, and why.
        Route::get('tickets/{ticketId}/recipients', [TicketController::class, 'recipients'])
            ->whereNumber('ticketId')->name('api.v1.stores.tickets.recipients');
    })->where('storeId', '[A-Za-z0-9._-]+');
});
