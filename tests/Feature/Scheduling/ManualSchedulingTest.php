<?php

use App\Enums\AcademicPeriodKind;
use App\Enums\AvailabilityKind;
use App\Enums\ResourceType;
use App\Enums\ScheduleResourceRole;
use App\Enums\TimetableVersionStatus;
use App\Models\AcademicPeriod;
use App\Models\AcademicUnit;
use App\Models\AcademicUnitType;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->withOwnedOrganization()->create();
    $this->organization = $this->user->currentOrganization;

    $year = AcademicYear::create([
        'organization_id' => $this->organization->id,
        'name' => '2026-2027',
        'starts_on' => '2026-06-01',
        'ends_on' => '2027-05-31',
        'status' => 'active',
    ]);

    $this->period = AcademicPeriod::create([
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

    $groupResource = SchedulingResource::create([
        'organization_id' => $this->organization->id,
        'type' => ResourceType::StudentGroup,
        'name' => 'BSIT-1A',
    ]);

    $this->group = StudentGroup::create([
        'organization_id' => $this->organization->id,
        'scheduling_resource_id' => $groupResource->id,
        'academic_year_id' => $year->id,
        'academic_unit_id' => $unit->id,
        'code' => 'BSIT-1A',
        'name' => 'BSIT-1A',
        'expected_headcount' => 35,
    ]);

    $this->facultyResource = SchedulingResource::create([
        'organization_id' => $this->organization->id,
        'type' => ResourceType::Faculty,
        'name' => 'Teacher A',
    ]);

    FacultyProfile::create([
        'organization_id' => $this->organization->id,
        'scheduling_resource_id' => $this->facultyResource->id,
        'employee_number' => 'FAC-001',
        'maximum_daily_minutes' => 360,
        'maximum_weekly_minutes' => 1200,
    ]);

    $roomTypeId = DB::table('room_types')->insertGetId([
        'organization_id' => $this->organization->id,
        'code' => 'classroom',
        'name' => 'Classroom',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->roomResource = SchedulingResource::create([
        'organization_id' => $this->organization->id,
        'type' => ResourceType::Room,
        'name' => 'Room 301',
    ]);

    $this->room = Room::create([
        'organization_id' => $this->organization->id,
        'scheduling_resource_id' => $this->roomResource->id,
        'room_type_id' => $roomTypeId,
        'code' => '301',
        'name' => 'Room 301',
        'capacity' => 40,
    ]);

    $subject = Subject::create([
        'organization_id' => $this->organization->id,
        'code' => 'CS101',
        'name' => 'Introduction to Programming',
    ]);

    $offering = SubjectOffering::create([
        'organization_id' => $this->organization->id,
        'academic_period_id' => $this->period->id,
        'subject_id' => $subject->id,
        'student_group_id' => $this->group->id,
        'owning_academic_unit_id' => $unit->id,
        'code' => 'CS101-BSIT1A',
        'expected_enrollment' => 35,
    ]);

    $this->component = OfferingComponent::create([
        'organization_id' => $this->organization->id,
        'subject_offering_id' => $offering->id,
        'kind' => 'lecture',
        'name' => 'Lecture',
        'weekly_minutes' => 90,
        'sessions_per_week' => 1,
        'duration_minutes' => 90,
        'minimum_room_capacity' => 35,
        'required_room_type_id' => $roomTypeId,
    ]);

    $timetable = Timetable::create([
        'organization_id' => $this->organization->id,
        'academic_year_id' => $year->id,
        'academic_period_id' => $this->period->id,
        'name' => 'First Semester Master Timetable',
        'timezone' => 'Asia/Manila',
        'scheduling_granularity' => 30,
    ]);

    $this->version = TimetableVersion::create([
        'organization_id' => $this->organization->id,
        'timetable_id' => $timetable->id,
        'version_number' => 1,
        'status' => TimetableVersionStatus::Draft,
        'created_by' => $this->user->id,
    ]);
});

function validSchedulePayload(object $test, int $startsAt = 480, int $endsAt = 570): array
{
    return [
        'timetable_version_id' => $test->version->public_id,
        'offering_component_id' => $test->component->public_id,
        'weekday' => 1,
        'starts_at_minute' => $startsAt,
        'ends_at_minute' => $endsAt,
        'resources' => [
            ['resource_id' => $test->facultyResource->public_id, 'role' => ScheduleResourceRole::Instructor->value],
            ['resource_id' => $test->group->resource->public_id, 'role' => ScheduleResourceRole::StudentGroup->value],
            ['resource_id' => $test->roomResource->public_id, 'role' => ScheduleResourceRole::Room->value],
        ],
    ];
}

function idempotencyHeaders(?string $key = null): array
{
    return ['Idempotency-Key' => $key ?? (string) Str::uuid()];
}

test('a valid manual schedule entry creates one canonical entry and resource reservations', function () {
    $response = $this->withHeaders(idempotencyHeaders())
        ->actingAs($this->user)
        ->postJson(route('scheduling.entries.store', $this->organization), validSchedulePayload($this));

    $response->assertCreated()
        ->assertJsonPath('data.type', 'schedule_entry')
        ->assertJsonPath('data.attributes.timetable_version_id', $this->version->public_id)
        ->assertJsonPath('data.attributes.offering_component_id', $this->component->public_id)
        ->assertJsonPath('data.attributes.weekday', 1)
        ->assertJsonCount(3, 'data.attributes.resources');

    $entry = ScheduleEntry::query()->sole();

    $response->assertJsonPath('data.id', $entry->public_id)
        ->assertJsonFragment(['resource_id' => $this->facultyResource->public_id]);

    $correlationId = $response->headers->get('X-Correlation-ID');

    expect($correlationId)->toBeString()->not->toBeEmpty();
    $response->assertJsonPath('meta.correlation_id', $correlationId);

    expect($response->json('data'))->not->toHaveKeys([
        'organization_id',
        'created_at',
        'updated_at',
    ]);

    $this->assertDatabaseCount('schedule_entries', 1);
    $this->assertDatabaseCount('schedule_entry_resources', 3);
    $this->assertDatabaseCount('schedule_reservations', 3);
});

test('overlapping resources return structured hard constraint issues', function () {
    $this->withHeaders(idempotencyHeaders())
        ->actingAs($this->user)
        ->postJson(route('scheduling.entries.store', $this->organization), validSchedulePayload($this))
        ->assertCreated();

    $existingEntry = ScheduleEntry::query()->sole();

    $response = $this->withHeaders(idempotencyHeaders())
        ->actingAs($this->user)
        ->postJson(route('scheduling.entries.store', $this->organization), validSchedulePayload($this, 540, 600));

    $response->assertUnprocessable()
        ->assertJsonPath('error.code', 'schedule_conflict')
        ->assertJsonFragment(['code' => 'resource_overlap', 'severity' => 'hard'])
        ->assertJsonFragment([
            'resource' => ['id' => $this->facultyResource->public_id, 'name' => 'Teacher A'],
            'conflicting_entry_id' => $existingEntry->public_id,
        ]);

    $this->assertDatabaseCount('schedule_entries', 1);
});

test('resource unavailability and room capacity are enforced', function () {
    ResourceAvailabilityRule::create([
        'organization_id' => $this->organization->id,
        'scheduling_resource_id' => $this->facultyResource->id,
        'academic_period_id' => $this->period->id,
        'kind' => AvailabilityKind::Unavailable,
        'weekday' => 1,
        'starts_at_minute' => 480,
        'ends_at_minute' => 720,
    ]);

    $this->room->update(['capacity' => 20]);

    $response = $this->actingAs($this->user)
        ->postJson(route('scheduling.entries.validate', $this->organization), validSchedulePayload($this));

    $response->assertSuccessful()
        ->assertJsonPath('data.attributes.valid', false)
        ->assertJsonFragment(['code' => 'resource_unavailable'])
        ->assertJsonFragment(['code' => 'room_capacity']);
});

test('published versions and off-grid times cannot be edited', function () {
    $this->version->update(['status' => TimetableVersionStatus::Published]);

    $response = $this->actingAs($this->user)
        ->postJson(route('scheduling.entries.validate', $this->organization), validSchedulePayload($this, 485, 575));

    $response->assertSuccessful()
        ->assertJsonFragment(['code' => 'version_not_editable'])
        ->assertJsonFragment(['code' => 'invalid_granularity']);
});

test('resources from another organization are rejected before scheduling', function () {
    $otherOrganization = Organization::factory()->create();
    $foreignResource = SchedulingResource::create([
        'organization_id' => $otherOrganization->id,
        'type' => ResourceType::Room,
        'name' => 'Foreign room',
    ]);

    $payload = validSchedulePayload($this);
    $payload['resources'][2]['resource_id'] = $foreignResource->public_id;

    $this->withHeaders(idempotencyHeaders())
        ->actingAs($this->user)
        ->postJson(route('scheduling.entries.store', $this->organization), $payload)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonFragment(['field' => 'resources.2.resource_id']);
});

test('internal numeric identifiers are rejected at the scheduling HTTP boundary', function () {
    $payload = validSchedulePayload($this);
    $payload['timetable_version_id'] = $this->version->id;
    $payload['offering_component_id'] = $this->component->id;
    $payload['resources'][0]['resource_id'] = $this->facultyResource->id;

    $this->withHeaders(idempotencyHeaders())
        ->actingAs($this->user)
        ->postJson(route('scheduling.entries.store', $this->organization), $payload)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonFragment(['field' => 'timetable_version_id'])
        ->assertJsonFragment(['field' => 'offering_component_id'])
        ->assertJsonFragment(['field' => 'resources.0.resource_id']);
});

test('schedule creation requires an idempotency key', function () {
    $this->actingAs($this->user)
        ->postJson(route('scheduling.entries.store', $this->organization), validSchedulePayload($this))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonFragment(['field' => 'idempotency_key']);

    $this->assertDatabaseCount('schedule_entries', 0);
});

test('replaying a completed schedule command returns the original resource without another write', function () {
    $key = (string) Str::uuid();
    $payload = validSchedulePayload($this);

    $firstResponse = $this->withHeaders(idempotencyHeaders($key))
        ->actingAs($this->user)
        ->postJson(route('scheduling.entries.store', $this->organization), $payload)
        ->assertCreated();

    $replayedResponse = $this->withHeaders(idempotencyHeaders($key))
        ->actingAs($this->user)
        ->postJson(route('scheduling.entries.store', $this->organization), $payload)
        ->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true');

    $replayedResponse->assertJsonPath('data.id', $firstResponse->json('data.id'));

    expect($replayedResponse->json('meta.correlation_id'))
        ->toBe($replayedResponse->headers->get('X-Correlation-ID'))
        ->not->toBe($firstResponse->json('meta.correlation_id'));

    $this->assertDatabaseCount('schedule_entries', 1);
    $this->assertDatabaseCount('idempotency_records', 1);
});

test('reusing an idempotency key with different input returns a conflict', function () {
    $key = (string) Str::uuid();

    $this->withHeaders(idempotencyHeaders($key))
        ->actingAs($this->user)
        ->postJson(route('scheduling.entries.store', $this->organization), validSchedulePayload($this))
        ->assertCreated();

    $this->withHeaders(idempotencyHeaders($key))
        ->actingAs($this->user)
        ->postJson(route('scheduling.entries.store', $this->organization), validSchedulePayload($this, 600, 690))
        ->assertConflict()
        ->assertJsonPath('error.code', 'idempotency_conflict');

    $this->assertDatabaseCount('schedule_entries', 1);
});
