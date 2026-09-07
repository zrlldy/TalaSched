<?php

use App\Enums\AcademicYearStatus;
use App\Enums\OrganizationRole;
use App\Enums\SubjectOfferingStatus;
use App\Enums\TimetableVersionStatus;
use App\Models\AcademicCalendar;
use App\Models\AcademicPeriod;
use App\Models\AcademicUnit;
use App\Models\AcademicYear;
use App\Models\FacultyProfile;
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
        ->assertDontSee('Add class')
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

test('resource sections preserve drafts and save clock times as local minutes', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    $resource = SchedulingResource::factory()->faculty()->create(['organization_id' => $organization->id]);
    $this->actingAs($owner);

    $page = visit(route('resources.setup', $organization))
        ->fill('Faculty name', 'Draft faculty member')
        ->click('button:text-is("Rooms")')
        ->assertSee('Room directory')
        ->assertDontSee('Faculty directory')
        ->click('button:text-is("Faculty")')
        ->assertValue('#faculty-resource-name', 'Draft faculty member')
        ->click('button:text-is("Availability")')
        ->select('Resource', $resource->public_id)
        ->select('Rule kind', 'available')
        ->fill('#starts-at', '08:30')
        ->fill('#ends-at', '17:00')
        ->click('Add availability rule')
        ->assertSee('Availability rule created.')
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
        ->assertValue('#period-sequence-'.$year->public_id, 2)
        ->assertDontSee('Save weekday hours')
        ->click('button:has-text("Teaching calendar")')
        ->assertSee('Save weekday hours')
        ->assertSee('Start time')
        ->assertSee('End time')
        ->assertScript('document.querySelectorAll(\'input[type="time"]\').length', 4)
        ->assertNoSmoke()
        ->screenshot(filename: 'workspace-academic-calendar');
});

test('academic administrators can create their first student group and enroll it in a period', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    $year = AcademicYear::factory()->forOrganization($organization)->create();
    $period = AcademicPeriod::factory()->forAcademicYear($year)->create(['name' => 'First semester']);
    $unit = AcademicUnit::factory()->forOrganization($organization)->create(['name' => 'Computer Science']);
    $this->actingAs($owner);

    visit(route('academic.setup', [$organization, 'section' => 'groups']))
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
        ->click('Enroll First semester')
        ->assertSee('Remove First semester')
        ->assertNoSmoke()
        ->screenshot(filename: 'workspace-student-groups');
    $group = StudentGroup::query()->where('organization_id', $organization->id)->sole();
    expect($group->periods()->sole()->is($period))->toBeTrue()
        ->and($group->resource->name)->toBe('Computer Science 1A');
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
