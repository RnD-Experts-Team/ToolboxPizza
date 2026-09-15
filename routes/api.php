<?php

use App\Http\Controllers\Api\V1\BreakController;
use App\Http\Controllers\Api\V1\BreakDayController;
use App\Http\Controllers\Api\V1\BreakMilestoneController;
use App\Http\Controllers\Api\V1\BreakNoteController;
use App\Http\Controllers\Api\V1\BreakSettingController;
use App\Http\Controllers\Api\V1\BreakTimerController;
use App\Http\Controllers\Api\V1\BreakTypeController;
use App\Http\Controllers\Api\V1\HealthController;
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
});
