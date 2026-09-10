<?php

use App\Http\Controllers\AcademicSetupController;
use App\Http\Controllers\Approvals\ApprovalController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExcelTemplateController;
use App\Http\Controllers\Organizations\OrganizationInvitationController;
use App\Http\Controllers\ResourceSetupController;
use App\Http\Controllers\Scheduling\ScheduleEntryController;
use App\Http\Controllers\Scheduling\ScheduleExceptionController;
use App\Http\Controllers\Scheduling\TimetableController;
use App\Http\Controllers\Scheduling\TimetableVersionComparisonController;
use App\Http\Controllers\Scheduling\TimetableVersionController;
use App\Http\Controllers\Scheduling\TimetableViewController;
use App\Http\Controllers\Scheduling\TimetableWorkspaceController;
use App\Http\Controllers\Subscriptions\SubscriptionController;
use App\Http\Middleware\EnsureOrganizationMembership;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::prefix('{current_organization}')
    ->middleware(['auth', 'verified', EnsureOrganizationMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::get('academic/setup', [AcademicSetupController::class, 'index'])->name('academic.setup');
        Route::get('resources/setup', [ResourceSetupController::class, 'index'])->name('resources.setup');
        Route::post('resources/faculty', [ResourceSetupController::class, 'storeFaculty'])->name('resources.faculty.store');
        Route::post('resources/room-types', [ResourceSetupController::class, 'storeRoomType'])->name('resources.room-types.store');
        Route::post('resources/features', [ResourceSetupController::class, 'storeFeature'])->name('resources.features.store');
        Route::post('resources/buildings', [ResourceSetupController::class, 'storeBuilding'])->name('resources.buildings.store');
        Route::post('resources/rooms', [ResourceSetupController::class, 'storeRoom'])->name('resources.rooms.store');
        Route::post('resources/availability', [ResourceSetupController::class, 'storeAvailability'])->name('resources.availability.store');
        Route::post('catalog/subjects', [ResourceSetupController::class, 'storeSubject'])->name('catalog.subjects.store');
        Route::post('catalog/components', [ResourceSetupController::class, 'storeSubjectComponent'])->name('catalog.components.store');
        Route::post('catalog/offerings', [ResourceSetupController::class, 'storeOffering'])->name('catalog.offerings.store');
        Route::post('catalog/offerings/components', [ResourceSetupController::class, 'addOfferingComponent'])->name('catalog.offerings.components');
        Route::post('catalog/offerings/instructors', [ResourceSetupController::class, 'assignOfferingInstructor'])->name('catalog.offerings.instructors');
        Route::post('catalog/offerings/status', [ResourceSetupController::class, 'updateOfferingStatus'])->name('catalog.offerings.status');
        Route::post('academic/years', [AcademicSetupController::class, 'storeYear'])->name('academic.years.store');
        Route::post('academic/years/{academic_year}/periods', [AcademicSetupController::class, 'storePeriod'])->name('academic.periods.store');
        Route::post('academic/years/{academic_year}/activate', [AcademicSetupController::class, 'activate'])->name('academic.years.activate');
        Route::post('academic/presets', [AcademicSetupController::class, 'applyPreset'])->name('academic.presets.apply');
        Route::post('academic/units', [AcademicSetupController::class, 'storeUnit'])->name('academic.units.store');
        Route::post('academic/units/{academic_unit}/move', [AcademicSetupController::class, 'moveUnit'])->name('academic.units.move');
        Route::post('academic/units/{academic_unit}/archive', [AcademicSetupController::class, 'archiveUnit'])->name('academic.units.archive');
        Route::post('academic/periods/{academic_period}/calendar', [AcademicSetupController::class, 'storeCalendar'])->name('academic.calendars.store');
        Route::post('academic/periods/{academic_period}/exceptions', [AcademicSetupController::class, 'storeCalendarException'])->name('academic.exceptions.store');
        Route::post('academic/groups/{student_group}/dates', [AcademicSetupController::class, 'updateGroupDates'])->name('academic.groups.dates');
        Route::post('academic/groups', [AcademicSetupController::class, 'storeGroup'])->name('academic.groups.store');
        Route::post('academic/groups/{student_group}/unit', [AcademicSetupController::class, 'assignGroupUnit'])->name('academic.groups.unit');
        Route::post('academic/groups/{student_group}/periods/{academic_period}/toggle', [AcademicSetupController::class, 'toggleGroupPeriod'])->name('academic.groups.periods.toggle');
        Route::post('academic/units', [AcademicSetupController::class, 'storeUnit'])->name('academic.units.store');
        Route::post('academic/units/{academic_unit}/move', [AcademicSetupController::class, 'moveUnit'])->name('academic.units.move');
        Route::post('academic/units/{academic_unit}/archive', [AcademicSetupController::class, 'archiveUnit'])->name('academic.units.archive');
        Route::post('scheduling/entries/validate', [ScheduleEntryController::class, 'validateEntry'])
            ->middleware('throttle:schedule-validation')
            ->name('scheduling.entries.validate');
        Route::post('scheduling/entries', [ScheduleEntryController::class, 'store'])->middleware('idempotent')->name('scheduling.entries.store');
        Route::patch('scheduling/entries/{schedule_entry}', [ScheduleEntryController::class, 'update'])->name('scheduling.entries.update');
        Route::delete('scheduling/entries/{schedule_entry}', [ScheduleEntryController::class, 'destroy'])->name('scheduling.entries.destroy');
        Route::post('scheduling/entries/{schedule_entry}/exceptions', [ScheduleExceptionController::class, 'store'])->name('scheduling.exceptions.store');
        Route::get('scheduling/timetables', [TimetableController::class, 'index'])->name('scheduling.timetables.index');
        Route::post('scheduling/timetables', [TimetableController::class, 'store'])->name('scheduling.timetables.store');
        Route::get('scheduling/timetables/{timetable}', [TimetableWorkspaceController::class, 'show'])->name('scheduling.timetables.show');
        Route::get('scheduling/timetables/{timetable}/views', [TimetableViewController::class, 'show'])->name('scheduling.timetables.views');
        Route::get('scheduling/timetables/{timetable}/versions/compare', [TimetableVersionComparisonController::class, 'show'])->name('scheduling.timetables.versions.compare');
        Route::post('scheduling/timetables/{timetable}/versions/{timetable_version}/clone', [TimetableVersionController::class, 'cloneVersion'])->name('scheduling.timetables.versions.clone');
        Route::post('scheduling/timetables/{timetable}/versions/{timetable_version}/submit', [TimetableVersionController::class, 'submitVersion'])->name('scheduling.timetables.versions.submit');
        Route::post('scheduling/timetables/{timetable}/versions/{timetable_version}/publish', [TimetableVersionController::class, 'publishVersion'])->name('scheduling.timetables.versions.publish');
        Route::post('scheduling/timetables/{timetable}/versions/{timetable_version}/rollback', [TimetableVersionController::class, 'rollbackVersion'])->name('scheduling.timetables.versions.rollback');
        Route::get('approvals', [ApprovalController::class, 'inbox'])->name('approvals.inbox');
        Route::post('approvals/{timetable_version}/decide', [ApprovalController::class, 'decide'])->name('approvals.decide');
        Route::get('approvals/workflows', [ApprovalController::class, 'workflows'])->name('approvals.workflows');
        Route::post('approvals/workflows', [ApprovalController::class, 'storeWorkflow'])->name('approvals.workflows.store');
        Route::post('approvals/workflows/{workflow}/versions/{version}/activate', [ApprovalController::class, 'activateWorkflow'])->name('approvals.workflows.versions.activate');
        Route::post('approvals/workflows/{workflow}/retire', [ApprovalController::class, 'retireWorkflow'])->name('approvals.workflows.retire');
        Route::get('approvals/signatories', [ApprovalController::class, 'signatories'])->name('approvals.signatories');
        Route::get('approvals/signatories/{signatory_profile}/signature', [ApprovalController::class, 'downloadSignatorySignature'])
            ->middleware('signed')
            ->name('approvals.signatories.signature.download');
        Route::post('approvals/signatories', [ApprovalController::class, 'storeSignatory'])
            ->middleware('throttle:uploads')
            ->name('approvals.signatories.store');
        Route::post('approvals/signatories/{signatory_profile}', [ApprovalController::class, 'updateSignatory'])
            ->middleware('throttle:uploads')
            ->name('approvals.signatories.update');
        Route::get('templates', [ExcelTemplateController::class, 'index'])->name('templates.index');
        Route::post('templates/workbooks', [ExcelTemplateController::class, 'storeWorkbook'])
            ->middleware('throttle:uploads')
            ->name('templates.workbooks.store');
        Route::post('templates/versions', [ExcelTemplateController::class, 'storeVersion'])->name('templates.versions.store');
        Route::post('templates/versions/{excel_template_version}/activate', [ExcelTemplateController::class, 'activateVersion'])
            ->name('templates.versions.activate');
        Route::post('templates/exports', [ExcelTemplateController::class, 'storeExport'])
            ->middleware('throttle:template-exports')
            ->name('templates.exports.store');
        Route::get('templates/exports/{export_run}/download', [ExcelTemplateController::class, 'download'])
            ->middleware('signed')
            ->name('templates.exports.download');
        Route::get('subscriptions', [SubscriptionController::class, 'show'])->name('subscriptions.show');
        Route::get('audit', [AuditController::class, 'index'])->name('audits.index');
    });

Route::middleware(['auth'])->group(function () {
    Route::post('invitations/{invitation}/accept', [OrganizationInvitationController::class, 'accept'])
        ->middleware('throttle:organization-invitation-responses')
        ->name('invitations.accept');
    Route::delete('invitations/{invitation}', [OrganizationInvitationController::class, 'decline'])
        ->middleware('throttle:organization-invitation-responses')
        ->name('invitations.decline');
});

require __DIR__.'/settings.php';
