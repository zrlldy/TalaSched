<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Organizations\OrganizationInvitationController;
use App\Http\Controllers\Scheduling\ScheduleEntryController;
use App\Http\Middleware\EnsureOrganizationMembership;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::prefix('{current_organization}')
    ->middleware(['auth', 'verified', EnsureOrganizationMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::post('scheduling/entries/validate', [ScheduleEntryController::class, 'validateEntry'])->name('scheduling.entries.validate');
        Route::post('scheduling/entries', [ScheduleEntryController::class, 'store'])->middleware('idempotent')->name('scheduling.entries.store');
    });

Route::middleware(['auth'])->group(function () {
    Route::post('invitations/{invitation}/accept', [OrganizationInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [OrganizationInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';
