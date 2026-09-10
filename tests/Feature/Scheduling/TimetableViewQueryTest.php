<?php

use App\Enums\AcademicPeriodKind;
use App\Enums\AcademicYearStatus;
use App\Enums\ScheduleExceptionAction;
use App\Enums\ScheduleResourceRole;
use App\Enums\SubjectComponentKind;
use App\Enums\SubjectOfferingStatus;
use App\Enums\TimetableVersionStatus;
use App\Models\AcademicPeriod;
use App\Models\AcademicUnit;
use App\Models\AcademicUnitType;
use App\Models\AcademicYear;
use App\Models\FacultyProfile;
use App\Models\OfferingComponent;
use App\Models\ScheduleEntry;
use App\Models\ScheduleEntryException;
use App\Models\ScheduleEntryResource;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use App\Models\User;
use App\Scheduling\TimetableViewFilters;
use App\Scheduling\TimetableViewQuery;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->user = User::factory()->withOwnedOrganization()->create();
    $this->organization = $this->user->currentOrganization;

    $year = AcademicYear::create([
        'organization_id' => $this->organization->id,
        'name' => '2026-2027',
        'starts_on' => '2026-06-01',
        'ends_on' => '2027-05-31',
        'status' => AcademicYearStatus::Active,
    ]);
    $period = AcademicPeriod::create([
        'organization_id' => $this->organization->id,
        'academic_year_id' => $year->id,
        'name' => 'First Semester',
        'kind' => AcademicPeriodKind::Semester,
        'sequence' => 1,
        'starts_on' => '2026-06-01',
        'ends_on' => '2026-10-31',
    ]);
    $unitType = AcademicUnitType::create([
        'organization_id' => $this->organization->id,
        'code' => 'program',
        'name' => 'Program',
    ]);
    $unit = AcademicUnit::create([
        'organization_id' => $this->organization->id,
        'academic_unit_type_id' => $unitType->id,
        'code' => 'BSIT',
        'name' => 'BS Information Technology',
    ]);
    $group = StudentGroup::factory()
        ->forAcademicYear($year)
        ->forAcademicUnit($unit)
        ->create([
            'organization_id' => $this->organization->id,
            'code' => 'BSIT-1A',
            'name' => 'BSIT-1A',
        ]);
    $subject = Subject::create([
        'organization_id' => $this->organization->id,
        'code' => 'CS101',
        'name' => 'Introduction to Programming',
    ]);
    $offering = SubjectOffering::factory()
        ->forAcademicPeriod($period)
        ->forSubject($subject)
        ->forStudentGroup($group)
        ->create([
            'organization_id' => $this->organization->id,
            'owning_academic_unit_id' => $unit->id,
            'code' => 'CS101-BSIT1A',
        ]);
    $component = OfferingComponent::create([
        'organization_id' => $this->organization->id,
        'subject_offering_id' => $offering->id,
        'kind' => SubjectComponentKind::Lecture,
        'name' => 'Lecture',
        'weekly_minutes' => 90,
        'sessions_per_week' => 1,
        'duration_minutes' => 90,
        'delivery_mode' => 'physical',
    ]);
    $timetable = Timetable::create([
        'organization_id' => $this->organization->id,
        'academic_year_id' => $year->id,
        'academic_period_id' => $period->id,
        'name' => 'First Semester Master Timetable',
        'timezone' => 'Asia/Manila',
        'scheduling_granularity' => 30,
    ]);
    $version = TimetableVersion::create([
        'organization_id' => $this->organization->id,
        'timetable_id' => $timetable->id,
        'version_number' => 1,
        'status' => TimetableVersionStatus::Draft,
        'created_by' => $this->user->id,
    ]);
    $entry = ScheduleEntry::factory()->create([
        'organization_id' => $this->organization->id,
        'timetable_version_id' => $version->id,
        'offering_component_id' => $component->id,
        'weekday' => 1,
        'starts_at_minute' => 480,
        'ends_at_minute' => 570,
    ]);

    $this->period = $period;
    $this->unit = $unit;
    $this->group = $group;
    $this->timetable = $timetable;
    $this->version = $version;
    $this->entry = $entry;
});

test('canonical timetable views filter one recurring entry across organization resource and unit scopes', function (): void {
    $entryResources = ScheduleEntryResource::query()
        ->where('schedule_entry_id', $this->entry->id)
        ->get()
        ->keyBy(fn (ScheduleEntryResource $assignment): string => $assignment->role->value);

    $cases = [
        ['scope' => 'organization'],
        [
            'scope' => 'teacher',
            'resource_id' => $entryResources[ScheduleResourceRole::Instructor->value]->resource->public_id,
        ],
        [
            'scope' => 'student_group',
            'resource_id' => $entryResources[ScheduleResourceRole::StudentGroup->value]->resource->public_id,
        ],
        [
            'scope' => 'room',
            'resource_id' => $entryResources[ScheduleResourceRole::Room->value]->resource->public_id,
        ],
        ['scope' => 'unit', 'unit_id' => $this->unit->public_id],
    ];

    foreach ($cases as $parameters) {
        $this->actingAs($this->user)
            ->getJson(route('scheduling.timetables.views', [
                $this->organization,
                $this->timetable,
                ...$parameters,
            ]))
            ->assertSuccessful()
            ->assertJsonPath('data.type', 'timetable_view')
            ->assertJsonCount(1, 'data.attributes.entries')
            ->assertJsonPath('data.attributes.entries.0.id', $this->entry->public_id)
            ->assertJsonPath('data.attributes.entries.0.offering.student_group.id', $this->group->public_id)
            ->assertJsonPath('data.attributes.context.version.id', $this->version->public_id);
    }

    expect(ScheduleEntry::query()->count())->toBe(1);
});

test('canonical timetable views use a bounded query count as entry volume grows', function (): void {
    $query = app(TimetableViewQuery::class);
    $filters = new TimetableViewFilters(
        scope: 'organization',
        versionPublicId: $this->version->public_id,
        resourcePublicId: null,
        unitPublicId: null,
        date: null,
        weekday: null,
    );

    DB::enableQueryLog();

    try {
        DB::flushQueryLog();
        $singleEntryView = $query->handle($this->organization, $this->timetable, $filters);
        $singleEntryQueryCount = count(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
    }

    ScheduleEntry::factory()->count(5)->create([
        'organization_id' => $this->organization->id,
        'timetable_version_id' => $this->version->id,
        'offering_component_id' => $this->entry->offering_component_id,
    ]);

    DB::enableQueryLog();

    try {
        DB::flushQueryLog();
        $multiEntryView = $query->handle($this->organization, $this->timetable, $filters);
        $multiEntryQueryCount = count(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
    }

    expect($singleEntryView->entries)->toHaveCount(1)
        ->and($multiEntryView->entries)->toHaveCount(6)
        ->and($singleEntryQueryCount)->toBe(13)
        ->and($multiEntryQueryCount)->toBe($singleEntryQueryCount);
});

test('canonical timetable views project dated cancellation and rescheduling without duplicating entries', function (): void {
    $cancelled = ScheduleEntryException::create([
        'organization_id' => $this->organization->id,
        'schedule_entry_id' => $this->entry->id,
        'date' => '2026-08-24',
        'action' => ScheduleExceptionAction::Cancelled,
        'reason' => 'Campus holiday',
    ]);
    ScheduleEntryException::create([
        'organization_id' => $this->organization->id,
        'schedule_entry_id' => $this->entry->id,
        'date' => '2026-08-31',
        'action' => ScheduleExceptionAction::Rescheduled,
        'starts_at_minute' => 600,
        'ends_at_minute' => 690,
        'reason' => 'Make-up time',
    ]);

    $this->actingAs($this->user)
        ->getJson(route('scheduling.timetables.views', [
            $this->organization,
            $this->timetable,
            'scope' => 'organization',
            'date' => '2026-08-24',
        ]))
        ->assertSuccessful()
        ->assertJsonPath('data.attributes.date', '2026-08-24')
        ->assertJsonPath('data.attributes.entries.0.status', 'cancelled')
        ->assertJsonPath('data.attributes.entries.0.starts_at_minute', null)
        ->assertJsonCount(0, 'data.attributes.entries.0.resources');

    $this->actingAs($this->user)
        ->getJson(route('scheduling.timetables.views', [
            $this->organization,
            $this->timetable,
            'scope' => 'organization',
            'date' => '2026-08-31',
        ]))
        ->assertSuccessful()
        ->assertJsonPath('data.attributes.entries.0.status', 'rescheduled')
        ->assertJsonPath('data.attributes.entries.0.starts_at_minute', 600)
        ->assertJsonPath('data.attributes.entries.0.ends_at_minute', 690)
        ->assertJsonCount(3, 'data.attributes.entries.0.resources');

    expect($cancelled->fresh()->action)->toBe(ScheduleExceptionAction::Cancelled)
        ->and(ScheduleEntry::query()->count())->toBe(1);
});

test('canonical timetable views reject a timetable from another organization', function (): void {
    $foreignTimetable = Timetable::factory()->create();

    $this->actingAs($this->user)
        ->getJson(route('scheduling.timetables.views', [
            $this->organization,
            $foreignTimetable,
            'scope' => 'organization',
        ]))
        ->assertNotFound();
});

test('timetable workspace renders the canonical view and preserves validated filters', function (): void {
    $this->actingAs($this->user)
        ->get(route('scheduling.timetables.show', [
            $this->organization,
            $this->timetable,
            'scope' => 'organization',
            'date' => '2026-08-24',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('scheduling/Workspace')
            ->where('view.scope', 'organization')
            ->where('view.date', '2026-08-24')
            ->where('filters.scope', 'organization')
            ->where('filters.date', '2026-08-24')
            ->has('view.entries', 1)
            ->has('versions')
            ->has('resources')
            ->has('units'));
});

test('class composer choices are public period scoped and limited to active eligible resources', function (): void {
    grantManualSchedulingEntitlement($this->organization);
    $component = $this->entry->offeringComponent;
    $faculty = FacultyProfile::factory()->forOrganization($this->organization)->create();
    $inactive = FacultyProfile::factory()->forOrganization($this->organization)->create();
    $inactive->resource->update(['is_active' => false]);
    $component->instructors()->attach([$faculty->id, $inactive->id], ['organization_id' => $this->organization->id, 'load_percentage' => 100]);
    OfferingComponent::factory()->create();
    $otherPeriod = AcademicPeriod::factory()->forAcademicYear($this->period->academicYear)->create(['sequence' => 2]);
    OfferingComponent::factory()->forOffering(SubjectOffering::factory()->forAcademicPeriod($otherPeriod)->create())->create();
    SubjectOffering::factory()->forAcademicPeriod($this->period)->create(['status' => SubjectOfferingStatus::Active]);
    SubjectOffering::factory()->forAcademicPeriod($this->period)->create(['status' => SubjectOfferingStatus::Draft]);
    SubjectOffering::factory()->forAcademicPeriod($otherPeriod)->create(['status' => SubjectOfferingStatus::Active]);

    $this->actingAs($this->user)->get(route('scheduling.timetables.show', [$this->organization, $this->timetable]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('offeringComponents', 1)
        ->where('emptyOfferingCount', 1)
        ->where('offeringComponents.0.id', $component->public_id)
        ->where('offeringComponents.0.group.id', $this->group->resource->public_id)
        ->has('offeringComponents.0.instructors', 1)
        ->where('offeringComponents.0.instructors.0.id', $faculty->resource->public_id)
        ->missing('offeringComponents.0.organization_id'));

    $component->offering->update(['status' => SubjectOfferingStatus::Inactive]);
    $this->get(route('scheduling.timetables.show', [$this->organization, $this->timetable]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('offeringComponents', []));
});
