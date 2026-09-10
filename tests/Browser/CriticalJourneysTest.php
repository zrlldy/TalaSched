<?php

use App\Approvals\ActivateApprovalWorkflowVersion;
use App\Approvals\CreateApprovalWorkflowVersion;
use App\Approvals\DecideTimetableApproval;
use App\Approvals\SubmitTimetableForApproval;
use App\Enums\AcademicYearStatus;
use App\Enums\ApprovalDecision;
use App\Enums\CapabilityKey;
use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Enums\SubjectOfferingStatus;
use App\Enums\TimetableVersionStatus;
use App\Jobs\GenerateTemplateExport;
use App\Models\AcademicCalendar;
use App\Models\AcademicPeriod;
use App\Models\AcademicUnit;
use App\Models\AcademicYear;
use App\Models\CalendarException;
use App\Models\ExcelTemplate;
use App\Models\ExcelTemplateVersion;
use App\Models\FacultyProfile;
use App\Models\FileAsset;
use App\Models\OfferingComponent;
use App\Models\Organization;
use App\Models\ResourceAvailabilityRule;
use App\Models\Room;
use App\Models\ScheduleEntry;
use App\Models\SchedulingResource;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use App\Models\User;
use App\Subscriptions\ProvisionOrganizationSubscription;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

test('schedulers can prepare an offering add a named class and safely edit its clock times', function (): void {
    $owner = User::factory()->withOwnedOrganization('Northfield Academy')->create();
    $organization = $owner->currentOrganization;
    grantManualSchedulingEntitlement($organization);
    $year = AcademicYear::factory()->forOrganization($organization)->create(['status' => AcademicYearStatus::Active]);
    $period = AcademicPeriod::factory()->forAcademicYear($year)->create();
    AcademicCalendar::factory()->forAcademicPeriod($period)->create();
    $subject = Subject::factory()->forOrganization($organization)->create(['code' => 'CS101', 'name' => 'Introduction to Programming']);
    $offering = SubjectOffering::factory()->forAcademicPeriod($period)->forSubject($subject)->create(['status' => SubjectOfferingStatus::Draft, 'expected_enrollment' => 25]);
    $component = OfferingComponent::factory()->forOffering($offering)->create();
    $faculty = FacultyProfile::factory()->forOrganization($organization)->create();
    $faculty->resource->update(['name' => 'Dr. Ada Lovelace']);
    $room = Room::factory()->forOrganization($organization)->create(['capacity' => 40]);
    $room->resource->update(['name' => 'Science Room 101']);
    $timetable = Timetable::factory()->create(['academic_period_id' => $period->id]);
    $version = TimetableVersion::factory()->create(['timetable_id' => $timetable->id, 'organization_id' => $organization->id, 'created_by' => $owner->id]);
    $this->actingAs($owner);

    visit(route('resources.setup', [$organization, 'section' => 'offerings']))
        ->click('Teaching team and readiness')
        ->select('#status-'.$offering->public_id, 'active')
        ->click('Update status')
        ->assertSee('Offering status updated.')
        ->select('#instructor-'.$component->public_id, $faculty->public_id)
        ->click('Assign instructor')
        ->assertSee('Eligible instructor assigned.')
        ->assertNoSmoke();

    $page = visit(route('scheduling.timetables.show', [$organization, $timetable]))
        ->click('Add class')
        ->select('#new-class-offering', $component->public_id)
        ->fill('#new-class-start', '03:00')
        ->select('#new-class-room', $room->resource->public_id)
        ->click('Save class')
        ->assertSee('What needs attention')
        ->assertSee('Save blocked')
        ->assertValue('#new-class-start', '03:00')
        ->fill('#new-class-start', '09:00')
        ->screenshot(filename: 'workspace-add-class')
        ->click('Save class')
        ->assertSee('Introduction to Programming')
        ->assertSee('Dr. Ada Lovelace')
        ->fill('#entry-start', '09:30')
        ->fill('#entry-end', '11:00')
        ->fill('#entry-notes', 'Updated from the timetable editor')
        ->click('Check and save')
        ->assertSee('Class saved. Conflict checks passed.')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'workspace-saved-class');

    $entry = ScheduleEntry::query()->where('timetable_version_id', $version->id)->sole();
    expect($entry->starts_at_minute)->toBe(570)->and($entry->ends_at_minute)->toBe(660)
        ->and($entry->lock_version)->toBe(2)->and($entry->resources()->count())->toBe(3);

    $page->fill('#entry-start', '03:00')->fill('#entry-end', '04:30')->click('Check and save')
        ->assertSee('Conflict status')->assertSee('Save blocked')
        ->assertValue('#entry-start', '03:00')->assertDontSee('Class saved. Conflict checks passed.')->assertNoJavaScriptErrors();
    expect($entry->fresh()->starts_at_minute)->toBe(570)->and($entry->fresh()->lock_version)->toBe(2);
});

test('schedulers can recover offerings created before subject requirements and add a class', function (bool $mobile): void {
    $owner = User::factory()->withOwnedOrganization('Northfield Academy')->create();
    $organization = $owner->currentOrganization;
    grantManualSchedulingEntitlement($organization);
    $period = AcademicPeriod::factory()->forAcademicYear(AcademicYear::factory()->forOrganization($organization)->create(['status' => AcademicYearStatus::Active]))->create();
    AcademicCalendar::factory()->forAcademicPeriod($period)->create();
    $subject = Subject::factory()->forOrganization($organization)->create(['code' => 'MATH101', 'name' => 'College Mathematics']);
    $offering = SubjectOffering::factory()->forAcademicPeriod($period)->forSubject($subject)->create(['status' => SubjectOfferingStatus::Active, 'expected_enrollment' => 20]);
    $faculty = FacultyProfile::factory()->forOrganization($organization)->create();
    $room = Room::factory()->forOrganization($organization)->create(['capacity' => 40]);
    $timetable = Timetable::factory()->create(['academic_period_id' => $period->id]);
    $version = TimetableVersion::factory()->create(['timetable_id' => $timetable->id, 'created_by' => $owner->id]);
    $this->actingAs($owner);
    $page = visit(route('scheduling.timetables.show', [$organization, $timetable]));
    if ($mobile) {
        $page->resize(390, 844);
    }

    $page->click('Add class')->assertSee('No schedulable classes yet')
        ->assertSee('1 active offering(s) in this period have no teaching components.')
        ->screenshot(filename: $mobile ? 'offering-blocker-mobile' : 'offering-blocker-desktop')
        ->click('Open subject offerings')->assertSee('This offering has no teaching components.')
        ->click('Set up subject requirements')->assertValue('#component-subject', $subject->public_id)
        ->fill('#component-name', 'Lecture')->fill('#weekly-minutes', '90')->fill('#sessions', '1')->fill('#duration', '90')
        ->select('#component-delivery', 'physical')->click('button:text-is("Add component")')
        ->assertSee('Subject component created.')->click('button:text-is("Offerings")')
        ->assertSee('Include these subject requirements in this offering:')
        ->click('Add subject requirements')->assertSee('Teaching components added. Assign an instructor to schedule each class.');
    $component = $offering->components()->sole();
    $page->click('Teaching team and readiness')
        ->select('#instructor-'.$component->public_id, $faculty->public_id)->click('Assign instructor')
        ->assertSee('Eligible instructor assigned.')->assertNoSmoke()
        ->screenshot(filename: $mobile ? 'offering-recovered-mobile' : 'offering-recovered-desktop');

    $page = visit(route('scheduling.timetables.show', [$organization, $timetable, 'date' => $period->starts_on->toDateString()]));
    if ($mobile) {
        $page->resize(390, 844);
    }
    $page->assertSee('Date view is read only.')->click('Show weekly timetable')->click('Add class')
        ->select('#new-class-offering', $component->public_id)->fill('#new-class-start', '09:00')
        ->select('#new-class-room', $room->resource->public_id)->click('Save class')
        ->assertSee('College Mathematics')->assertNoSmoke()
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth');
    expect($version->entries()->count())->toBe(1);
})->with(['desktop' => false, 'mobile' => true]);

test('resource records support searchable tables and cards', function (bool $mobile, bool $dark): void {
    $owner = User::factory()->withOwnedOrganization('Northfield Academy')->create();
    $organization = $owner->currentOrganization;
    Room::factory()->forOrganization($organization)->create(['code' => 'SCI-101', 'name' => 'Science Laboratory', 'capacity' => 40]);
    Subject::factory()->forOrganization($organization)->create(['code' => 'BIO101', 'name' => 'Introduction to Biology']);
    $this->actingAs($owner);
    $page = $dark ? visit(route('resources.setup', [$organization, 'section' => 'rooms']))->inDarkMode() : visit(route('resources.setup', [$organization, 'section' => 'rooms']));
    if ($mobile) {
        $page->resize(390, 844);
    }
    $page->assertSee('Science Laboratory')->assertSee('40');
    if (! $mobile) {
        $page->assertSeeIn('table:visible', 'Science Laboratory')->click('button:text-is("Cards"):visible')
            ->assertSeeIn('article[data-test="record-row"]:visible', 'Science Laboratory')
            ->click('button:text-is("Table"):visible');
    }
    $page->fill('#resource-search', 'missing')->assertSee('No rooms match your search.')
        ->fill('#resource-search', 'SCI-101')->assertSee('Science Laboratory')
        ->screenshot(filename: $mobile ? 'rooms-records-mobile' : ($dark ? 'rooms-records-dark' : 'rooms-records-desktop'))
        ->fill('#resource-search', '')->click('button:text-is("Subjects")')->assertSee('Introduction to Biology')
        ->assertSee('Add lecture or lab requirements before scheduling')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')->assertNoSmoke();
})->with(['desktop' => [false, false], 'mobile' => [true, false], 'dark' => [false, true]]);

test('owners can reach plan and usage from the workspace dashboard', function (): void {
    $this->seed(SubscriptionCatalogSeeder::class);
    $owner = User::factory()->withOwnedOrganization('Owner Academy')->create();
    $organization = $owner->currentOrganization;
    app(ProvisionOrganizationSubscription::class)->handle($organization, $owner);

    $this->actingAs($owner);

    visit(route('dashboard', ['current_organization' => $organization->slug]))
        ->assertSee('Workspace overview')
        ->assertSee('Owner Academy')
        ->click('Plan & usage')
        ->assertSee('Current plan')
        ->assertSee('Starter')
        ->assertNoSmoke();
});

test('scheduling administrators can open an empty timetable workspace', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    grantManualSchedulingEntitlement($organization);
    $scheduler = User::factory()->create();
    $organization->members()->attach($scheduler, ['role' => OrganizationRole::Admin]);
    $scheduler->switchOrganization($organization);

    $year = AcademicYear::factory()->forOrganization($organization)->create([
        'status' => AcademicYearStatus::Active,
    ]);
    $period = AcademicPeriod::factory()->forAcademicYear($year)->create();
    $timetable = Timetable::factory()->create([
        'organization_id' => $organization->id,
        'academic_year_id' => $year->id,
        'academic_period_id' => $period->id,
        'name' => 'Fall scheduling workspace',
    ]);
    TimetableVersion::factory()->create([
        'organization_id' => $organization->id,
        'timetable_id' => $timetable->id,
        'created_by' => $owner->id,
    ]);

    $this->actingAs($scheduler);

    visit(route('scheduling.timetables.show', [
        'current_organization' => $organization->slug,
        'timetable' => $timetable->public_id,
    ]))
        ->assertSee('Fall scheduling workspace')
        ->assertSee('No schedule entries are in this view.')
        ->assertNoSmoke();
});

test('published timetables show early and late classes without editable controls', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    grantManualSchedulingEntitlement($organization);
    $period = AcademicPeriod::factory()->forAcademicYear(AcademicYear::factory()->forOrganization($organization)->create())->create();
    $timetable = Timetable::factory()->create(['academic_period_id' => $period->id]);
    $version = TimetableVersion::factory()->create(['timetable_id' => $timetable->id, 'organization_id' => $organization->id, 'created_by' => $owner->id, 'status' => TimetableVersionStatus::Published]);
    ScheduleEntry::factory()->create(['timetable_version_id' => $version->id, 'starts_at_minute' => 30, 'ends_at_minute' => 120]);
    ScheduleEntry::factory()->create(['timetable_version_id' => $version->id, 'starts_at_minute' => 1350, 'ends_at_minute' => 1440]);
    $this->actingAs($owner);

    visit(route('scheduling.timetables.show', [$organization, $timetable]))
        ->assertSee('12:00 AM')
        ->assertSee('Midnight')
        ->assertNotPresent('button:text-is("Add class")')
        ->assertDisabled('#entry-start')
        ->assertSee('This version is read-only.')
        ->assertNoSmoke();
});

test('approval administrators can open an empty approval inbox', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    grantApprovalWorkflowsEntitlement($organization);
    $approver = User::factory()->create();
    $organization->members()->attach($approver, ['role' => OrganizationRole::Admin]);
    $approver->switchOrganization($organization);

    $this->actingAs($approver);

    visit(route('approvals.inbox', ['current_organization' => $organization->slug]))
        ->assertSee('Approval inbox')
        ->assertSee('Nothing is waiting on you')
        ->assertSee('Workflow designer')
        ->assertNoSmoke();
});

test('organization viewers see a usable dashboard without administrative navigation', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    $viewer = User::factory()->create();
    $organization->members()->attach($viewer, ['role' => OrganizationRole::Member]);
    $viewer->switchOrganization($organization);

    $this->actingAs($viewer);

    visit(route('dashboard', ['current_organization' => $organization->slug]))
        ->assertSee('Your school workspace')
        ->assertDontSee('Plan & usage')
        ->assertNoSmoke();
});

test('owners can switch between organizations from the sidebar', function (): void {
    $owner = User::factory()->withOwnedOrganization('Primary Academy')->create();
    $primaryOrganization = $owner->currentOrganization;
    $secondaryOrganization = Organization::factory()
        ->ownedBy($owner)
        ->create(['name' => 'Secondary Academy']);

    $this->actingAs($owner);

    visit(route('dashboard', ['current_organization' => $primaryOrganization->slug]))
        ->assertSee('Primary Academy')
        ->click('@organization-switcher-trigger')
        ->assertSee('Secondary Academy')
        ->click('Secondary Academy')
        ->wait(1)
        ->assertSee('Setup checklist')
        ->assertSee('Secondary Academy')
        ->assertNoSmoke();

    expect($owner->fresh()->current_organization_id)->toBe($secondaryOrganization->id);
});

test('owners can create their first timetable from the global navigation', function (): void {
    $this->seed(SubscriptionCatalogSeeder::class);
    $owner = User::factory()->withOwnedOrganization('Northfield School')->create();
    $organization = $owner->currentOrganization;
    app(ProvisionOrganizationSubscription::class)->handle($organization, $owner);
    $year = AcademicYear::factory()->forOrganization($organization)->create(['name' => '2026–2027']);
    $period = AcademicPeriod::factory()->forAcademicYear($year)->create(['name' => 'First quarter']);
    $this->actingAs($owner);

    visit(route('dashboard', $organization))
        ->assertSee('Setup checklist')
        ->screenshot(filename: 'workspace-dashboard')
        ->click('[data-slot="sidebar"] a:has-text("Timetables")')
        ->assertSee('No timetables yet')
        ->click('button:text-is("Create timetable")')
        ->fill('Timetable name', 'Northfield weekly schedule')
        ->select('Academic period', $period->public_id)
        ->click('button[type="submit"]:text-is("Create timetable")')
        ->assertSee('Northfield weekly schedule')
        ->assertSee('No schedule entries are in this view.')
        ->assertNoSmoke();

    expect(Timetable::query()->where('organization_id', $organization->id)->count())->toBe(1);
});

test('teachers can be added with optional load limits and correct field feedback', function (bool $mobile): void {
    $owner = User::factory()->withOwnedOrganization('Northfield Academy')->create();
    $organization = $owner->currentOrganization;
    $this->actingAs($owner);
    $page = visit(route('resources.setup', [$organization, 'section' => 'faculty']));
    if ($mobile) {
        $page->resize(390, 844);
    }

    $page->assertDontSee('Teaching load limits')
        ->click('button:text-is("Add teacher")')
        ->assertSee('Teaching load limits')
        ->assertSee('Leave either field blank if no limit is needed.')
        ->fill('#faculty-resource-name', 'Teacher Maria Santos')
        ->click('summary:has-text("Employment and academic unit")')->fill('#employee-number', 'FAC-2026-01')->click('summary:has-text("Employment and academic unit")')
        ->fill('#daily-limit', '480')
        ->fill('#weekly-limit', '120')
        ->click('button:text-is("Save teacher")')
        ->assertSee('Check the teacher details')
        ->assertSee('The weekly teaching limit must be at least the daily teaching limit.')
        ->assertAttribute('[data-slot="validation-summary"] a:has-text("Weekly teaching limit")', 'href', '#weekly-limit')
        ->assertAttribute('#weekly-limit', 'aria-invalid', 'true')
        ->assertValue('#faculty-resource-name', 'Teacher Maria Santos')
        ->assertValue('#daily-limit', '480')
        ->fill('#weekly-limit', '2400')
        ->click('[role="dialog"] button:text-is("Close")')
        ->click('button:text-is("Rooms")')
        ->click('button:text-is("Faculty")')
        ->click('button:text-is("Add teacher")')
        ->assertValue('#weekly-limit', '2400')
        ->screenshot(filename: $mobile ? 'faculty-load-limits-mobile' : 'faculty-load-limits-desktop')
        ->click('button:text-is("Save teacher")')
        ->assertSee('Faculty profile created.')
        ->assertSeeIn('[data-test="faculty-row"]:visible', 'Teacher Maria Santos')
        ->assertSeeIn('[data-test="faculty-row"]:visible', '8h 0m')
        ->assertSeeIn('[data-test="faculty-row"]:visible', '40h 0m')
        ->click('button:text-is("Add teacher")')
        ->assertValue('#faculty-resource-name', '')
        ->assertValue('#daily-limit', '')
        ->assertValue('#weekly-limit', '')
        ->fill('#faculty-resource-name', 'Teacher Alex Reyes')
        ->click('button:text-is("Save teacher")')
        ->assertSee('Teacher Alex Reyes')
        ->assertSeeIn('[data-test="faculty-row"]:visible:has-text("Teacher Alex Reyes") [data-test="daily-teaching-limit"]', 'No limit')
        ->assertSeeIn('[data-test="faculty-row"]:visible:has-text("Teacher Alex Reyes") [data-test="weekly-teaching-limit"]', 'No limit')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoSmoke()
        ->screenshot(filename: $mobile ? 'faculty-directory-mobile' : 'faculty-directory-desktop');

    $profiles = FacultyProfile::query()->where('organization_id', $organization->id)->with('resource')->get()->keyBy('resource.name');
    expect($profiles)->toHaveCount(2)
        ->and($profiles['Teacher Maria Santos']->maximum_daily_minutes)->toBe(480)
        ->and($profiles['Teacher Maria Santos']->maximum_weekly_minutes)->toBe(2400)
        ->and($profiles['Teacher Maria Santos']->employee_number)->toBe('FAC-2026-01')
        ->and($profiles['Teacher Alex Reyes']->maximum_daily_minutes)->toBeNull()
        ->and($profiles['Teacher Alex Reyes']->maximum_weekly_minutes)->toBeNull();
})->with(['desktop' => false, 'mobile' => true]);

test('resource sections preserve drafts and save clock times as local minutes', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    $resource = SchedulingResource::factory()->faculty()->create(['organization_id' => $organization->id]);
    $this->actingAs($owner);

    $page = visit(route('resources.setup', $organization))
        ->click('button:text-is("Add teacher")')
        ->fill('Teacher name', 'Draft faculty member')
        ->click('[role="dialog"] button:text-is("Close")')
        ->click('button:text-is("Rooms")')
        ->assertSee('Room directory')
        ->assertDontSee('Faculty directory')
        ->click('button:text-is("Faculty")')
        ->click('button:text-is("Add teacher")')
        ->assertValue('#faculty-resource-name', 'Draft faculty member')
        ->click('[role="dialog"] button:text-is("Close")')
        ->click('button:text-is("Availability")')
        ->assertSee("Times use your organization's local timezone.")
        ->select('Resource', $resource->public_id)
        ->select('Rule kind', 'available')
        ->fill('#starts-at', '08:30')
        ->fill('#ends-at', '17:00')
        ->click('Add availability rule')
        ->assertSee('Availability rule created.')
        ->assertSeeIn('[data-test="availability-row"]:visible', '08:30 - 17:00')
        ->assertQueryStringHas('section', 'availability')
        ->assertNoSmoke();

    $rule = ResourceAvailabilityRule::query()->where('organization_id', $organization->id)->sole();
    expect($rule->starts_at_minute)->toBe(510)
        ->and($rule->ends_at_minute)->toBe(1020);
    $page->screenshot(filename: 'workspace-availability');
});

test('academic sections reveal clock fields only in the teaching calendar', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    $year = AcademicYear::factory()->forOrganization($organization)->create();
    AcademicPeriod::factory()->forAcademicYear($year)->create();
    $this->actingAs($owner);

    visit(route('academic.setup', $organization))
        ->click('button:text-is("Add period")')
        ->assertValue('#period-sequence', 2)
        ->click('[role="dialog"] button:text-is("Close")')
        ->assertDontSee('Save weekday hours')
        ->click('button:has-text("Teaching calendar")')
        ->assertDontSee('Save weekday hours')
        ->click('button[aria-label="Set Monday hours"]')
        ->assertSee('Save weekday hours')
        ->assertSee('Start time')
        ->assertSee('End time')
        ->assertScript('document.querySelectorAll(\'input[type="time"]\').length', 2)
        ->assertNoSmoke()
        ->screenshot(filename: 'workspace-academic-calendar');
});

test('academic year dialogs preserve drafts and period creation recovers validation errors', function (bool $mobile): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    $existingYear = AcademicYear::factory()->forOrganization($organization)->create(['name' => 'Future year', 'starts_on' => '2028-08-01', 'ends_on' => '2029-07-31']);
    $this->actingAs($owner);
    $page = visit(route('academic.setup', $organization));
    if ($mobile) {
        $page->resize(390, 844);
    }
    $page->fill('#academic-year-search', 'no matching year')->assertSee('No academic years match your filters.')
        ->click('Add academic year')->fill('#year-name', '2026-2027')->fill('#year-start', '2026-08-01')->fill('#year-end', '2026-01-01')
        ->click('Create draft year')->assertSee('Check the academic year')->assertAttribute('#year-end', 'aria-invalid', 'true')
        ->click('[role="dialog"] button:text-is("Close")')->click('button:text-is("School structure")')
        ->click('button:text-is("Years & periods")')->click('Add academic year')->assertValue('#year-name', '2026-2027')
        ->fill('#year-end', '2027-07-31')->click('Create draft year')->assertSee('Academic year created.')
        ->assertValue('#academic-year-search', '')->assertSee('Periods in 2026-2027')->assertDisabled('button:text-is("Activate year")')
        ->click('Add academic year')->assertValue('#year-name', '')->click('[role="dialog"] button:text-is("Close")')
        ->click('button:text-is("Add period")')->fill('#period-name', 'First semester')->fill('#period-start', '2026-07-01')->fill('#period-end', '2026-12-31')
        ->click('Create period')->assertSee('The period start date must fall within the academic year.')
        ->assertAttribute('[data-slot="validation-summary"] a:has-text("Start date")', 'href', '#period-start')
        ->click('[role="dialog"] button:text-is("Close")')->click('button[aria-label="View periods for Future year"]:visible')
        ->click('button:text-is("Add period")')->assertValue('#period-name', '')->click('[role="dialog"] button:text-is("Close")')
        ->click('button[aria-label="View periods for 2026-2027"]:visible')->click('button:text-is("Add period")')->assertValue('#period-name', 'First semester')
        ->fill('#period-start', '2026-08-01')->click('Create period')->assertSee('Academic period created.')
        ->click('button:text-is("Add period")')->assertValue('#period-name', '')->assertValue('#period-sequence', 2)
        ->fill('#period-name', 'Second semester')->fill('#period-sequence', '1')->fill('#period-start', '2027-01-01')->fill('#period-end', '2027-05-31')
        ->click('Create period')->assertSee('This sequence is already used')->assertAttribute('#period-sequence', 'aria-invalid', 'true')
        ->fill('#period-sequence', '2')->fill('#period-start', '2026-12-15')->click('Create period')->assertSee('Academic periods in one year cannot overlap.')
        ->fill('#period-start', '2027-01-01')->click('Create period')->assertSee('Second semester')
        ->screenshot(filename: $mobile ? 'academic-years-mobile' : 'academic-years-desktop')
        ->click('Activate year')->assertSee('You cannot add more periods after activation.')
        ->click('Confirm activation')->assertSee('Academic year activated.')->assertDontSee('Add period')->assertDontSee('Activate year')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)->assertNoSmoke();

    $year = AcademicYear::query()->where('organization_id', $organization->id)->where('name', '2026-2027')->sole();
    expect($year->status)->toBe(AcademicYearStatus::Active)->and($year->periods()->count())->toBe(2)
        ->and($existingYear->fresh()->status)->toBe(AcademicYearStatus::Draft);
})->with(['desktop' => false, 'mobile' => true]);

test('academic activation failures stay visible and can be corrected with a missing period', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    $year = AcademicYear::factory()->forOrganization($organization)->create(['name' => '2026-2027', 'starts_on' => '2026-08-01', 'ends_on' => '2027-07-31']);
    AcademicPeriod::factory()->forAcademicYear($year)->create(['name' => 'Second term', 'sequence' => 2, 'starts_on' => '2027-01-01', 'ends_on' => '2027-05-31']);
    $this->actingAs($owner);
    visit(route('academic.setup', $organization))->click('Activate year')->click('Confirm activation')
        ->assertSee('This year could not be activated')->assertSee('Academic period sequences must be contiguous and start at one.')
        ->click('[role="dialog"] button:text-is("Close")')->click('button:text-is("Add period")')->assertValue('#period-sequence', 1)
        ->fill('#period-name', 'First term')->fill('#period-start', '2026-08-01')->fill('#period-end', '2026-12-31')->click('Create period')
        ->click('Activate year')->click('Confirm activation')->assertSee('Academic year activated.')->assertNoSmoke();
    expect($year->fresh()->status)->toBe(AcademicYearStatus::Active);
});

test('teaching calendar records edit saved hours and recover exception drafts', function (bool $mobile, bool $dark): void {
    $owner = User::factory()->withOwnedOrganization('Northfield Academy')->create();
    $organization = $owner->currentOrganization;
    $organization->update(['timezone' => 'Asia/Manila']);
    $year = AcademicYear::factory()->forOrganization($organization)->create(['name' => '2026-2027', 'starts_on' => '2026-08-01', 'ends_on' => '2027-07-31']);
    $period = AcademicPeriod::factory()->forAcademicYear($year)->create(['name' => 'First semester', 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-31']);
    $second = AcademicPeriod::factory()->forAcademicYear($year)->create(['name' => 'Second semester', 'sequence' => 2, 'starts_on' => '2027-01-01', 'ends_on' => '2027-05-31']);
    AcademicCalendar::factory()->forAcademicPeriod($period)->create(['weekday' => 1, 'starts_at_minute' => 480, 'ends_at_minute' => 960]);
    $this->actingAs($owner);
    $page = visit(route('academic.setup', [$organization, 'section' => 'calendar']), ['colorScheme' => $dark ? 'dark' : 'light']);
    if ($mobile) {
        $page->resize(390, 844);
    }
    $page->assertSee('Weekly teaching hours')->assertSee('Asia/Manila')->assertSeeIn('[data-test="calendar-weekday-1"]', '08:00 - 16:00')
        ->click('button[aria-label="Edit Monday hours"]')->assertValue('#calendar-start', '08:00')->assertValue('#calendar-end', '16:00')
        ->fill('#calendar-start', '17:00')->click('Save weekday hours')->assertSee('Check the calendar details')->assertAttribute('#calendar-end', 'aria-invalid', 'true')
        ->fill('#calendar-end', '18:00')->click('Save weekday hours')->assertSeeIn('[data-test="calendar-weekday-1"]', '17:00 - 18:00')
        ->assertScript('document.activeElement?.getAttribute("aria-label")', 'Edit Monday hours')
        ->click('Add exception')->fill('#exception-name', 'Foundation day')->fill('#exception-date', '2027-01-01')->click('Save date exception')
        ->assertSee('Calendar exceptions must fall within the academic period.')->assertAttribute('#exception-date', 'aria-invalid', 'true')
        ->fill('#exception-date', '2026-09-15')->select('#exception-kind', 'teaching')->fill('#calendar-start', '09:00')
        ->click('Save date exception')->assertSee('Calendar exception windows require both start and end minutes.')
        ->click('[role="dialog"] button:text-is("Close")')->select('#selected-period', $second->public_id)
        ->click('Add exception')->assertValue('#exception-name', '')->click('[role="dialog"] button:text-is("Close")')
        ->select('#selected-period', $period->public_id)->click('Add exception')->assertValue('#exception-name', 'Foundation day')->assertValue('#calendar-start', '09:00')
        ->select('#exception-kind', 'holiday')->assertNotPresent('#calendar-start')->click('Save date exception')
        ->assertSee('Foundation day')->assertSee('No classes all day')
        ->fill('#calendar-exception-search', 'missing')->assertSee('No exceptions match your search.')->click('button:text-is("Clear"):visible')
        ->screenshot(filename: $mobile ? 'teaching-calendar-mobile' : ($dark ? 'teaching-calendar-dark' : 'teaching-calendar-desktop'))
        ->click('Add exception')->assertValue('#exception-name', '')->fill('#exception-date', '2026-09-15')->fill('#exception-name', 'Special teaching day')
        ->assertSee('Saving replaces the exception')->select('#exception-kind', 'teaching')->fill('#calendar-start', '10:00')->fill('#calendar-end', '12:00')
        ->screenshot(filename: $mobile ? 'calendar-dialog-mobile' : 'calendar-dialog-desktop')
        ->click('Replace date exception')->assertSee('Special teaching day')->assertDontSee('Foundation day')
        ->click('button[aria-label="Edit Special teaching day"]:visible')->assertValue('#calendar-start', '10:00')->assertValue('#calendar-end', '12:00')
        ->assertAttribute('#exception-date', 'readonly', '')->click('[role="dialog"] button:text-is("Close")')
        ->assertQueryStringHas('section', 'calendar')->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)->assertNoSmoke();
    $exception = CalendarException::query()->where('academic_period_id', $period->id)->sole();
    expect($exception->name)->toBe('Special teaching day')->and($exception->starts_at_minute)->toBe(600)->and($exception->ends_at_minute)->toBe(720)
        ->and($period->calendars()->sole()->starts_at_minute)->toBe(1020)
        ->and(CalendarException::query()->where('academic_period_id', $second->id)->count())->toBe(0);
})->with(['desktop' => [false, false], 'mobile' => [true, false], 'dark' => [false, true]]);

test('academic calendars explain prerequisites and respect closed years and viewers', function (string $state): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    $actor = $owner;
    if ($state !== 'empty') {
        $year = AcademicYear::factory()->forOrganization($organization)->create(['status' => $state === 'closed' ? AcademicYearStatus::Closed : AcademicYearStatus::Active]);
        AcademicPeriod::factory()->forAcademicYear($year)->create();
    }
    if ($state === 'viewer') {
        $actor = User::factory()->create();
        $organization->members()->attach($actor, ['role' => OrganizationRole::Member]);
        $actor->switchOrganization($organization);
    }
    $this->actingAs($actor);
    $page = visit(route('academic.setup', [$organization, 'section' => 'calendar']));
    if ($state === 'empty') {
        $page->assertSee('Create an academic year first')->click('Go to years & periods')->assertSee('Add academic year');
    } else {
        $page->assertSee('View only. Calendar changes require')->assertDontSee('Set hours')->assertDontSee('Add exception')
            ->click('button:text-is("Years & periods")')->assertDontSee('Add period')->assertDontSee('Activate year');
    }
    $page->assertNoSmoke();
})->with(['empty', 'closed', 'viewer']);

test('academic administrators can create their first student group and enroll it in a period', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    $year = AcademicYear::factory()->forOrganization($organization)->create();
    $period = AcademicPeriod::factory()->forAcademicYear($year)->create(['name' => 'First semester']);
    $unit = AcademicUnit::factory()->forOrganization($organization)->create(['name' => 'Computer Science']);
    $this->actingAs($owner);

    visit(route('academic.setup', [$organization, 'section' => 'groups']))
        ->click('button:text-is("Add student group")')
        ->assertSee('Add a student group')
        ->select('#group-year', $year->public_id)
        ->select('#group-unit', $unit->public_id)
        ->fill('#group-code', 'BSCS-1A')
        ->fill('#group-name', 'Computer Science 1A')
        ->fill('#group-headcount', '30')
        ->click('Create student group')
        ->assertSee('Student group created.')
        ->assertQueryStringHas('section', 'groups')
        ->assertSee('Computer Science 1A')
        ->click('button[aria-label="Manage Computer Science 1A"]:visible')
        ->click('button[aria-label="Enroll First semester"]')
        ->assertPresent('button[aria-label="Remove First semester"]')
        ->assertNoSmoke()
        ->screenshot(filename: 'workspace-student-groups');
    $group = StudentGroup::query()->where('organization_id', $organization->id)->sole();
    expect($group->periods()->sole()->is($period))->toBeTrue()
        ->and($group->resource->name)->toBe('Computer Science 1A');
});

test('student group directories filter records and recover enrollment errors without losing drafts', function (bool $mobile, bool $dark): void {
    $owner = User::factory()->withOwnedOrganization('Northfield Academy')->create();
    $organization = $owner->currentOrganization;
    $year = AcademicYear::factory()->forOrganization($organization)->create(['name' => '2026-2027', 'starts_on' => '2026-08-01', 'ends_on' => '2027-07-31', 'status' => AcademicYearStatus::Active]);
    $period = AcademicPeriod::factory()->forAcademicYear($year)->create(['name' => 'First semester', 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-31']);
    $unit = AcademicUnit::factory()->forOrganization($organization)->create(['name' => 'Computer Science']);
    $otherUnit = AcademicUnit::factory()->forOrganization($organization)->create(['name' => 'Engineering']);
    $group = StudentGroup::factory()->forAcademicYear($year)->forAcademicUnit($unit)->create(['name' => 'Computer Science 1A', 'code' => 'BSCS-1A', 'active_from' => '2027-01-01', 'active_until' => '2027-05-31']);
    StudentGroup::factory()->forAcademicYear($year)->forAcademicUnit($unit)->create(['name' => 'Computer Science 1B', 'code' => 'BSCS-1B']);
    $this->actingAs($owner);
    $page = $dark ? visit(route('academic.setup', [$organization, 'section' => 'groups']))->inDarkMode() : visit(route('academic.setup', [$organization, 'section' => 'groups']));
    if ($mobile) {
        $page->resize(390, 844);
    }
    if (! $mobile) {
        $page->assertSeeIn('table:visible', 'Computer Science 1A')->click('button:text-is("Cards"):visible')
            ->assertSeeIn('article[data-test="student-group-row"]:visible:first-child', 'Computer Science 1A')
            ->click('button:text-is("Table"):visible');
    }
    $page->select('#student-group-year-filter', $year->public_id)->select('#student-group-unit-filter', $unit->public_id)
        ->fill('#student-group-search', 'BSCS-1A')->assertDontSee('Computer Science 1B')
        ->screenshot(filename: $mobile ? 'student-groups-mobile' : ($dark ? 'student-groups-dark' : 'student-groups-desktop'))
        ->click('button[aria-label="Manage Computer Science 1A"]:visible')->click('button[aria-label="Enroll First semester"]')
        ->assertSee('The student group is not active during the selected period.')
        ->assertPresent('button[aria-label="Enroll First semester"]')
        ->screenshot(filename: $mobile ? 'group-enrollment-error-mobile' : 'group-enrollment-error-desktop')
        ->click('Review active dates')->assertValue('#group-active-from', '2027-01-01')
        ->fill('#group-active-from', '2027-05-31')->fill('#group-active-until', '2027-01-01')->click('Save dates')
        ->assertSee('Check the active dates')->assertAttribute('#group-active-until', 'aria-invalid', 'true')
        ->assertValue('#group-active-from', '2027-05-31')
        ->assertAttribute('[data-slot="validation-summary"]:visible a:has-text("Active until")', 'href', '#group-active-until')
        ->fill('#group-active-from', '2026-08-01')->fill('#group-active-until', '2026-12-31')->click('Save dates')
        ->assertSee('Dates saved.')->assertQueryStringHas('section', 'groups')
        ->fill('#group-active-from', '2026-08-02')->click('button:text-is("Participating periods")')
        ->click('button[aria-label="Enroll First semester"]')->assertPresent('button[aria-label="Remove First semester"]')
        ->assertSee('Participation saved.')->click('[role="dialog"] button:text-is("Close")')
        ->assertValue('#student-group-search', 'BSCS-1A')
        ->assertScript('document.activeElement?.getAttribute("aria-label")', 'Manage Computer Science 1A')
        ->fill('#student-group-search', '')->click('button[aria-label="Manage Computer Science 1B"]:visible')
        ->click('button:text-is("Dates & unit")')->assertValue('#group-active-from', '')
        ->click('[role="dialog"] button:text-is("Close")')->fill('#student-group-search', 'BSCS-1A')->click('button[aria-label="Manage Computer Science 1A"]:visible')
        ->click('button:text-is("Dates & unit")')->assertValue('#group-active-from', '2026-08-02')
        ->select('#group-assigned-unit', $otherUnit->public_id)->click('Assign unit')->assertSee('School unit saved.')
        ->assertValue('#group-active-from', '2026-08-02')
        ->screenshot(filename: $mobile ? 'group-details-mobile' : ($dark ? 'group-details-dark' : 'group-details-desktop'))
        ->click('[role="dialog"] button:text-is("Close")')->assertSee('No student groups match these filters.')
        ->assertScript('document.activeElement?.id', 'student-group-search')
        ->click('Clear filters')->assertSee('Computer Science 1A')->assertSee('Engineering')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')->assertNoSmoke();
    expect($group->fresh()->active_from->toDateString())->toBe('2026-08-01')
        ->and($group->fresh()->academic_unit_id)->toBe($otherUnit->id)
        ->and($group->periods()->sole()->is($period))->toBeTrue();
})->with(['desktop' => [false, false], 'mobile' => [true, false], 'dark' => [false, true]]);

test('student group creation keeps corrections and resets only after success', function (bool $mobile): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    $year = AcademicYear::factory()->forOrganization($organization)->create();
    $unit = AcademicUnit::factory()->forOrganization($organization)->create();
    StudentGroup::factory()->forAcademicYear($year)->forAcademicUnit($unit)->create(['code' => 'BSCS-1A']);
    $this->actingAs($owner);
    $page = visit(route('academic.setup', [$organization, 'section' => 'groups']));
    if ($mobile) {
        $page->resize(390, 844);
    }
    $page->fill('#student-group-search', 'missing')->assertSee('No student groups match these filters.')
        ->click('button:text-is("Add student group")')->select('#group-year', $year->public_id)->select('#group-unit', $unit->public_id)
        ->fill('#group-code', 'BSCS-1A')->fill('#group-name', 'Computer Science 1B')->fill('#group-headcount', '30')
        ->click('Create student group')->assertSee('This group code is already used in the selected academic year, including archived groups.')
        ->assertAttribute('#group-code', 'aria-invalid', 'true')
        ->assertAttribute('[data-slot="validation-summary"] a:has-text("Group code")', 'href', '#group-code')
        ->fill('#group-code', 'BSCS-1B')->click('[role="dialog"] button:text-is("Close")')
        ->click('button:text-is("Teaching calendar")')->click('button:text-is("Student groups")')
        ->click('button:text-is("Add student group")')->assertValue('#group-code', 'BSCS-1B')
        ->assertValue('#group-headcount', '30')->click('Create student group')->assertSee('Student group created.')
        ->assertValue('#student-group-search', '')->assertSee('Computer Science 1B')
        ->click('button:text-is("Add student group")')->assertValue('#group-code', '')->assertValue('#group-headcount', '0')
        ->screenshot(filename: $mobile ? 'create-student-group-mobile' : 'create-student-group-desktop')
        ->assertNoSmoke();
    expect(StudentGroup::query()->where('organization_id', $organization->id)->count())->toBe(2);
})->with(['desktop' => false, 'mobile' => true]);

test('closed year groups and viewers have read only group details', function (bool $viewer): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    $year = AcademicYear::factory()->forOrganization($organization)->create(['status' => $viewer ? AcademicYearStatus::Active : AcademicYearStatus::Closed]);
    AcademicPeriod::factory()->forAcademicYear($year)->create(['name' => 'First semester']);
    $unit = AcademicUnit::factory()->forOrganization($organization)->create();
    $group = StudentGroup::factory()->forAcademicYear($year)->forAcademicUnit($unit)->create(['name' => 'Computer Science 1A']);
    $actor = $owner;
    if ($viewer) {
        $actor = User::factory()->create();
        $organization->members()->attach($actor, ['role' => OrganizationRole::Member]);
        $actor->switchOrganization($organization);
    }
    $this->actingAs($actor);
    $page = visit(route('academic.setup', [$organization, 'section' => 'groups']))
        ->assertDontSee('Add student group')->click('button[aria-label="View Computer Science 1A"]:visible')
        ->assertSee($viewer ? 'Ask an academic administrator' : 'This academic year is closed.')
        ->assertNotPresent('button[aria-label="Enroll First semester"]')->click('button:text-is("Dates & unit")')
        ->assertNotPresent('#group-active-from')->assertNotPresent('#group-assigned-unit')->assertNoSmoke();
    expect($group->periods()->count())->toBe(0);
})->with(['closed year' => false, 'viewer' => true]);

test('student groups link missing prerequisites to the appropriate setup section', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $this->actingAs($owner);
    visit(route('academic.setup', [$owner->currentOrganization, 'section' => 'groups']))
        ->assertSee('No student groups yet.')->assertDontSee('Add student group')
        ->click('Set up an academic year')->assertQueryStringHas('section', 'years')->assertNoSmoke();
});

test('mobile users can find pages through search and see focused resource sections', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $this->actingAs($owner);

    visit(route('dashboard', $owner->currentOrganization))->on()->mobile()
        ->click('@workspace-search')
        ->fill('[aria-label="Search pages"]', 'rooms')
        ->click('[role="dialog"] a:has-text("Rooms")')
        ->assertSee('Room directory')
        ->assertDontSee('Faculty directory')
        ->assertQueryStringHas('section', 'rooms')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoSmoke()
        ->screenshot(filename: 'workspace-mobile-rooms');
});

test('template navigation stays visible without creation access', function (): void {
    $owner = User::factory()->withOwnedOrganization('Northfield Academy')->create();
    $this->actingAs($owner);

    visit(route('dashboard', $owner->currentOrganization))
        ->click('[data-slot="sidebar"] a:has-text("Templates & exports")')
        ->assertSee('Your saved templates are still available')
        ->assertVisible('[data-slot="sidebar"] a:has-text("Templates & exports")')
        ->assertDontSee('New template')
        ->click('button:has-text("Export history")')
        ->assertSee('No exports yet')
        ->click('button:has-text("Workbook mapper")')
        ->assertSee('Template changes are unavailable')
        ->click('[data-slot="sidebar"] a:has-text("Approval inbox")')
        ->assertSee('Nothing is waiting on you')
        ->assertVisible('[data-slot="sidebar"] a:has-text("Templates & exports")')
        ->assertNoSmoke()
        ->screenshot(filename: 'approval-inbox-empty');
});

test('template library supports search and queues previews into export history', function (bool $mobile, bool $dark): void {
    Queue::fake();
    $owner = User::factory()->withOwnedOrganization('Northfield Academy')->create();
    $organization = $owner->currentOrganization;
    grantCustomExcelTemplatesEntitlement($organization);
    $asset = FileAsset::factory()->create(['organization_id' => $organization->id, 'scan_status' => FileAsset::ScanClean, 'original_name' => 'registrar-weekly-layout.xlsx']);
    $template = ExcelTemplate::factory()->forOrganization($organization)->create(['name' => 'Registrar weekly layout']);
    ExcelTemplateVersion::factory()->forTemplate($template, $asset)->create();
    $year = AcademicYear::factory()->forOrganization($organization)->create();
    $period = AcademicPeriod::factory()->forAcademicYear($year)->create();
    $timetable = Timetable::factory()->create(['academic_period_id' => $period->id, 'name' => 'First semester timetable']);
    TimetableVersion::factory()->create(['organization_id' => $organization->id, 'timetable_id' => $timetable->id]);
    $this->actingAs($owner);

    $page = $dark
        ? visit(route('templates.index', $organization))->inDarkMode()
        : visit(route('templates.index', $organization));
    if ($mobile) {
        $page->resize(390, 844);
    }

    $page->assertSee('Registrar weekly layout')
        ->assertDontSee('Upload your workbook')
        ->fill('[aria-label="Search templates"]', 'missing layout')
        ->assertSee('No matching templates')
        ->click('Clear search')
        ->assertSee('Registrar weekly layout')
        ->assertButtonDisabled('Export published')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->screenshot(filename: $mobile ? 'template-library-mobile' : ($dark ? 'template-library-dark' : 'template-library-desktop'))
        ->click('button:text-is("Preview")')
        ->assertSee('Previews & exports')
        ->assertSee('pending')
        ->assertNoSmoke();

    Queue::assertPushed(GenerateTemplateExport::class);
})->with(['desktop' => [false, false], 'mobile' => [true, false], 'dark' => [false, true]]);

test('workbook mapping drafts survive section changes on mobile', function (): void {
    Storage::fake(FileAsset::PRIVATE_DISK);
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    grantCustomExcelTemplatesEntitlement($organization);
    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->setTitle('Weekly timetable')->setCellValue('F10', 'Friday');
    $path = 'browser-template.xlsx';
    (new Xlsx($spreadsheet))->save(Storage::disk(FileAsset::PRIVATE_DISK)->path($path));
    $spreadsheet->disconnectWorksheets();
    $asset = FileAsset::factory()->create([
        'organization_id' => $organization->id,
        'path' => $path,
        'scan_status' => FileAsset::ScanClean,
        'original_name' => 'weekly-timetable.xlsx',
    ]);
    $this->actingAs($owner);

    visit(route('templates.index', [$organization, 'workbook' => $asset->public_id]))->on()->mobile()
        ->assertSee('Weekly timetable')
        ->fill('#template-name', 'Draft registrar template')
        ->click('button:has-text("Template library")')
        ->assertDontSee('Save draft version')
        ->click('button:has-text("Workbook mapper")')
        ->assertValue('#template-name', 'Draft registrar template')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoSmoke()
        ->screenshot(filename: 'template-mapper-mobile');
});

test('approval filters select the visible request and isolate decision comments', function (): void {
    $owner = User::factory()->withOwnedOrganization('Northfield Academy')->create();
    $organization = $owner->currentOrganization;
    grantApprovalWorkflowsEntitlement($organization);
    $reviewer = User::factory()->create(['name' => 'Morgan Reyes']);
    $organization->members()->attach($reviewer, ['role' => OrganizationRole::Admin]);
    $reviewer->switchOrganization($organization);
    $workflow = app(CreateApprovalWorkflowVersion::class)->handle($organization, $owner, 'Academic review', [[
        'sequence' => 1,
        'label' => 'Registrar review',
        'approver_selector_type' => 'permission',
        'required_permission' => OrganizationPermission::ManageScheduling->value,
        'role_codes' => [],
        'minimum_approvals' => 1,
        'allow_self_approval' => false,
        'signatory_slot' => 'registrar',
    ]]);
    app(ActivateApprovalWorkflowVersion::class)->handle($organization, $owner, $workflow);
    $year = AcademicYear::factory()->forOrganization($organization)->create(['status' => AcademicYearStatus::Active]);
    foreach (['Science timetable', 'Arts timetable', 'Previous term timetable'] as $index => $name) {
        $period = AcademicPeriod::factory()->forAcademicYear($year)->create(['sequence' => $index + 1]);
        $timetable = Timetable::factory()->create(['academic_period_id' => $period->id, 'name' => $name]);
        $version = TimetableVersion::factory()->create(['organization_id' => $organization->id, 'timetable_id' => $timetable->id, 'created_by' => $owner->id]);
        $instanceId = app(SubmitTimetableForApproval::class)->handle($version, $workflow, $owner);
        if ($name === 'Previous term timetable') {
            app(DecideTimetableApproval::class)->handle($instanceId, ApprovalDecision::Reject, (string) Str::uuid(), $reviewer, 'Revise the room assignments.');
        }
    }
    $this->actingAs($reviewer);

    $page = visit(route('approvals.inbox', $organization))
        ->click('button:has-text("Science timetable")')
        ->fill('#approval-comment', 'Science review only')
        ->click('button:has-text("Arts timetable")')
        ->assertValue('#approval-comment', '')
        ->assertSeeIn('[aria-label="Selected approval request"]', 'Arts timetable')
        ->screenshot(filename: 'approval-inbox-desktop')
        ->click('button:has-text("History")')
        ->assertSeeIn('[aria-label="Selected approval request"]', 'Previous term timetable')
        ->assertSee('Revise the room assignments.')
        ->assertDontSee('Record your decision')
        ->click('button:has-text("Pending")')
        ->fill('[aria-label="Search approval requests"]', 'Science')
        ->assertSeeIn('[aria-label="Selected approval request"]', 'Science timetable')
        ->assertDontSee('Arts timetable')
        ->click('Open timetable')
        ->assertQueryStringHas('version_id')
        ->assertSee('Science timetable')
        ->assertNoSmoke();

    $page->navigate(route('approvals.inbox', $organization))->resize(390, 844)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->screenshot(filename: 'approval-inbox-mobile')
        ->click('button:has-text("Science timetable")')
        ->fill('#approval-comment', 'Please review the room assignments.')
        ->click('button:text-is("Request changes")')
        ->click('button:has-text("History")')
        ->click('button:has-text("Science timetable")')
        ->assertSeeIn('[aria-label="Selected approval request"] > div:first-child', 'Changes Requested')
        ->assertSee('Please review the room assignments.')
        ->assertDontSee('Record your decision')
        ->assertNoSmoke();
});

test('workflow designers preserve the remaining steps and activate a saved review path', function (bool $mobile): void {
    $owner = User::factory()->withOwnedOrganization('Northfield Academy')->create();
    $organization = $owner->currentOrganization;
    grantApprovalWorkflowsEntitlement($organization);
    $this->actingAs($owner);
    $page = visit(route('approvals.inbox', $organization));
    if ($mobile) {
        $page->resize(390, 844);
    }

    $page->click('[aria-label="Approval navigation"] a:has-text("Workflow designer")')
        ->assertAttribute('[aria-label="Approval navigation"] a:has-text("Workflow designer")', 'aria-current', 'page')
        ->assertSee('No workflows yet')
        ->click('button:text-is("New workflow")')
        ->fill('#workflow-name', 'Academic timetable review')
        ->fill('#step-label-0', 'Registrar review')
        ->select('#step-permission-0', OrganizationPermission::ManageScheduling->value)
        ->click('Add step')
        ->fill('#step-label-1', 'Remove this review')
        ->click('Add step')
        ->fill('#step-label-2', 'Dean review')
        ->fill('#step-slot-2', 'dean')
        ->fill('#step-minimum-2', '2')
        ->select('#step-selector-2', 'role')
        ->click('[aria-label="Remove step 2"]')
        ->assertValue('#step-label-1', 'Dean review')
        ->assertValue('#step-slot-1', 'dean')
        ->assertValue('#step-minimum-1', '2')
        ->assertSelected('#step-selector-1', 'role')
        ->click('button:has-text("Saved workflows")')
        ->assertDontSee('Draft a workflow version')
        ->click('button:has-text("Draft workflow")')
        ->assertValue('#workflow-name', 'Academic timetable review')
        ->assertValue('#step-label-1', 'Dean review')
        ->click('Save draft version')
        ->assertSee('Check the workflow details')
        ->assertValue('#step-label-1', 'Dean review')
        ->select('#step-role-1', [OrganizationRole::Admin->value])
        ->screenshot(filename: $mobile ? 'workflow-draft-mobile' : 'workflow-draft-desktop')
        ->click('Save draft version')
        ->assertSee('Workflow draft created.')
        ->assertSee('Academic timetable review')
        ->assertDontSee('Draft a workflow version')
        ->click('Activate version')
        ->assertSee('Workflow version activated.')
        ->assertSee('Retire workflow')
        ->fill('[aria-label="Search workflows"]', 'missing workflow')
        ->assertSee('No matching workflows')
        ->click('Clear search')
        ->assertSee('Academic timetable review')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoSmoke()
        ->screenshot(filename: $mobile ? 'workflow-library-mobile' : 'workflow-library-desktop');

    $steps = DB::table('approval_workflow_steps')->where('organization_id', $organization->id)->orderBy('sequence')->get();
    expect($steps->pluck('label')->all())->toBe(['Registrar review', 'Dean review'])
        ->and($steps->last()->minimum_approvals)->toBe(2)
        ->and($steps->last()->signatory_slot)->toBe('dean');
})->with(['desktop' => false, 'mobile' => true]);

test('signatory administrators can navigate create search and correct profile details', function (): void {
    $owner = User::factory()->withOwnedOrganization('Northfield Academy')->create(['name' => 'Morgan Reyes']);
    $organization = $owner->currentOrganization;
    grantApprovalWorkflowsEntitlement($organization);
    $membership = $organization->memberships()->where('user_id', $owner->id)->sole();
    $this->actingAs($owner);

    $page = visit(route('approvals.workflows', $organization))->inDarkMode()
        ->click('[aria-label="Approval navigation"] a:has-text("Signatory profiles")')
        ->assertSee('No signatory profiles yet')
        ->assertAttribute('[aria-label="Approval navigation"] a:has-text("Signatory profiles")', 'aria-current', 'page')
        ->click('button:text-is("Add profile")')
        ->select('#profile-member', $membership->public_id)
        ->fill('#profile-name', 'Morgan Reyes')
        ->fill('#profile-position', 'Registrar')
        ->fill('#profile-from', '2026-09-01')
        ->fill('#profile-until', '2026-08-01')
        ->click('button:has-text("Saved profiles")')
        ->click('button:has-text("New profile")')
        ->assertValue('#profile-name', 'Morgan Reyes')
        ->click('Save signatory profile')
        ->assertSee('Check the signatory details')
        ->assertValue('#profile-position', 'Registrar')
        ->fill('#profile-until', '2027-08-31')
        ->click('Save signatory profile')
        ->assertSee('Signatory profile created.')
        ->assertNoJavaScriptErrors()
        ->assertSee('People who sign')
        ->assertDontSee('Add a signatory profile')
        ->fill('[aria-label="Search signatory profiles"]', 'missing person')
        ->assertSee('No matching profiles')
        ->click('Clear search')
        ->click('summary:has-text("Morgan Reyes")');

    $profile = $organization->signatoryProfiles()->sole();
    $page->fill('#edit-position-'.$profile->public_id, 'Senior Registrar')
        ->fill('#edit-until-'.$profile->public_id, '2026-08-01')
        ->click('Update profile')
        ->assertSee('Check the profile changes')
        ->assertValue('#edit-position-'.$profile->public_id, 'Senior Registrar')
        ->fill('#edit-until-'.$profile->public_id, '2027-08-31')
        ->click('Update profile')
        ->assertSee('Signatory profile updated.')
        ->assertSee('Senior Registrar')
        ->assertNoSmoke()
        ->screenshot(filename: 'signatory-directory-dark')
        ->resize(390, 844)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->screenshot(filename: 'signatory-directory-mobile')
        ->click('[aria-label="Approval navigation"] a:has-text("Approval inbox")')
        ->assertSee('Approval inbox')
        ->assertNoSmoke();

    expect($profile->fresh()->position)->toBe('Senior Registrar');
});

test('approval navigation hides management pages from viewers', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    $viewer = User::factory()->create();
    $organization->members()->attach($viewer, ['role' => OrganizationRole::Member]);
    $viewer->switchOrganization($organization);
    $this->actingAs($viewer);

    visit(route('approvals.inbox', $organization))
        ->assertDontSee('Workflow designer')
        ->assertDontSee('Signatory profiles')
        ->assertSee('Nothing is waiting on you')
        ->assertNoSmoke();
});

test('organization directories preserve settings drafts and manage member access', function (bool $mobile, bool $dark): void {
    $owner = User::factory()->withOwnedOrganization('Northfield Academy')->create(['name' => 'Morgan Reyes']);
    $organization = $owner->currentOrganization;
    $member = User::factory()->create(['name' => 'Alexandra Santos', 'email' => 'alexandra.santos.registrar@northfield-academy.example']);
    $organization->members()->attach($member, ['role' => OrganizationRole::Member]);
    $this->actingAs($owner);
    $page = $dark ? visit(route('organizations.edit', $organization))->inDarkMode() : visit(route('organizations.edit', $organization));
    if ($mobile) {
        $page->resize(390, 844);
    }

    $page->assertSee('Member directory')
        ->assertDontSee('Manage your profile and account settings')
        ->assertNotPresent('[aria-label="Remove Morgan Reyes"]')
        ->screenshot(filename: $mobile ? 'members-mobile' : ($dark ? 'members-dark' : 'members-desktop'))
        ->fill('[aria-label="Search members"]', 'not a colleague')
        ->assertSee('No matching members')->click('Clear filters')
        ->select('[aria-label="Filter members by role"]', 'owner')
        ->assertSee('Showing 1 of 2 members')->assertDontSee('Alexandra Santos')
        ->select('[aria-label="Filter members by role"]', '')
        ->fill('[aria-label="Search members"]', 'alexandra.santos')
        ->click('[aria-label="Change role for Alexandra Santos"]')
        ->click('[data-test="member-role-option"]:has-text("Admin")')
        ->assertSee('Member role updated.')
        ->assertSeeIn('[aria-label="Change role for Alexandra Santos"]', 'Admin')
        ->click('[aria-label="Organization sections"] button:has-text("Details")')
        ->fill('#organization-name', 'Northfield Learning Academy')
        ->click('[aria-label="Organization sections"] button:has-text("Members")')
        ->assertValue('[aria-label="Search members"]', 'alexandra.santos')
        ->click('[aria-label="Organization sections"] button:has-text("Details")')
        ->assertValue('#organization-name', 'Northfield Learning Academy')
        ->fill('#organization-name', str_repeat('N', 256))
        ->click('[data-test="organization-save-button"]')
        ->assertSee('Check the organization details')
        ->assertValue('#organization-name', str_repeat('N', 256))
        ->fill('#organization-name', 'Northfield Learning Academy')
        ->click('[data-test="organization-save-button"]')
        ->assertSee('Organization updated.')
        ->click('[aria-label="Organization sections"] button:has-text("Members")')
        ->fill('[aria-label="Search members"]', '')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->click('[aria-label="Remove Alexandra Santos"]')
        ->assertSee('They will need a new invitation to rejoin.')
        ->click('[role="dialog"] button:text-is("Cancel")')
        ->assertSee('Alexandra Santos')
        ->click('[aria-label="Remove Alexandra Santos"]')
        ->click('[data-test="remove-member-confirm"]')
        ->assertSee('Member removed.')
        ->assertDontSee('Alexandra Santos')
        ->assertNoSmoke();

    expect($organization->fresh()->name)->toBe('Northfield Learning Academy')
        ->and($organization->memberships()->where('user_id', $member->id)->exists())->toBeFalse();
})->with(['desktop' => [false, false], 'mobile' => [true, false], 'dark' => [false, true]]);

test('organization invitations show corrections reset after success and require cancellation confirmation', function (): void {
    Notification::fake();
    $owner = User::factory()->withOwnedOrganization('Northfield Academy')->create(['email' => 'owner@northfield.example']);
    $organization = $owner->currentOrganization;
    $this->actingAs($owner);

    $page = visit(route('organizations.edit', $organization))->on()->mobile();
    $page->click('[aria-label="Organization sections"] button:has-text("Invitations")')
        ->assertSee('No pending invitations')
        ->click('[data-test="invite-member-button"]')
        ->fill('#invite-email', $owner->email)
        ->click('[data-test="invite-submit"]')
        ->assertSee('Check the invitation details')
        ->assertValue('#invite-email', $owner->email)
        ->fill('#invite-email', 'colleague@northfield.example')
        ->click('[data-test="invite-submit"]')
        ->assertSee('Invitation sent.')
        ->assertSee('colleague@northfield.example')
        ->assertAttribute('[aria-label="Organization sections"] button:has-text("Invitations")', 'aria-current', 'page')
        ->click('[data-test="invite-member-button"]')
        ->assertValue('#invite-email', '')
        ->assertDontSee('Check the invitation details')
        ->click('[role="dialog"] button:text-is("Cancel")')
        ->fill('[aria-label="Search invitations"]', 'missing')
        ->assertSee('No matching invitations')->click('Clear search')
        ->screenshot(filename: 'invitations-mobile')
        ->click('[data-test="invitation-cancel-button"]')
        ->click('Keep invitation')
        ->assertSee('colleague@northfield.example')
        ->click('[data-test="invitation-cancel-button"]')
        ->click('[data-test="cancel-invitation-confirm"]')
        ->assertSee('Invitation cancelled.')
        ->assertSee('No pending invitations')
        ->assertNoSmoke();

    expect($organization->invitations()->count())->toBe(0);
});

test('organization roles support validation editing search and an accessible deletion dialog', function (): void {
    $owner = User::factory()->withOwnedOrganization('Northfield Academy')->create();
    $organization = $owner->currentOrganization;
    grantOrganizationEntitlement($organization, 'custom-roles-test', 'Custom roles test', CapabilityKey::CustomRoles);
    $this->actingAs($owner);
    $permissionSelector = 'input[name="permissions[]"][value="'.OrganizationPermission::ManageAcademic->value.'"]';

    $page = visit(route('organizations.edit', $organization))->inDarkMode();
    $page->click('[aria-label="Organization sections"] button:has-text("Roles")')
        ->assertScript('document.querySelector("[data-test=role-row] summary").getBoundingClientRect().height >= 40')
        ->click('[data-test="create-role-button"]')
        ->fill('#role-name', str_repeat('R', 121))
        ->check($permissionSelector)
        ->click('[data-test="save-role-button"]')
        ->assertSee('Check the role details')
        ->assertChecked($permissionSelector)
        ->fill('#role-name', 'Department coordinator')
        ->click('[data-test="save-role-button"]')
        ->assertSee('Custom role created.')
        ->fill('[aria-label="Search roles"]', 'Department')
        ->click('[aria-label="Edit Department coordinator"]')
        ->assertValue('#role-name', 'Department coordinator')
        ->assertChecked($permissionSelector)
        ->fill('#role-name', 'Academic coordinator')
        ->click('[data-test="save-role-button"]')
        ->assertSee('Custom role updated.')
        ->assertValue('[aria-label="Search roles"]', '')
        ->assertSee('Academic coordinator')
        ->fill('[aria-label="Search roles"]', 'Missing role')
        ->assertSee('No matching roles')->click('Clear search')
        ->screenshot(filename: 'organization-roles-dark')
        ->click('[aria-label="Delete Academic coordinator"]')
        ->assertSee('Delete custom role')
        ->assertSee('Only roles with no assigned members can be deleted.')
        ->click('Keep role')
        ->assertSee('Academic coordinator')
        ->click('[aria-label="Delete Academic coordinator"]')
        ->click('[data-test="delete-role-confirm"]')
        ->assertSee('Custom role deleted.')
        ->assertDontSee('Academic coordinator')
        ->assertNoSmoke();

    expect($organization->roles()->where('code', 'department-coordinator')->exists())->toBeFalse();
});

test('organization viewers can browse sections without administrative controls', function (): void {
    $owner = User::factory()->withOwnedOrganization('Northfield Academy')->create();
    $organization = $owner->currentOrganization;
    $viewer = User::factory()->create();
    $organization->members()->attach($viewer, ['role' => OrganizationRole::Member]);
    $viewer->switchOrganization($organization);
    $this->actingAs($viewer);

    visit(route('organizations.edit', $organization))->on()->mobile()
        ->assertSee('Member directory')
        ->assertNotPresent('[data-test="invite-member-button"]')
        ->assertNotPresent('[data-test="member-role-trigger"]')
        ->assertNotPresent('[data-test="member-remove-button"]')
        ->click('[aria-label="Organization sections"] button:has-text("Roles")')
        ->assertSee('Custom roles are not included in this plan')
        ->assertSee('Built-in')
        ->assertNotPresent('[data-test="create-role-button"]')
        ->click('[aria-label="Organization sections"] button:has-text("Details")')
        ->assertSee('Northfield Academy')
        ->assertNotPresent('[data-test="organization-save-button"]')
        ->assertNotPresent('[data-test="delete-organization-button"]')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoSmoke();
});
