<?php

use App\Enums\AcademicPeriodKind;
use App\Enums\AvailabilityKind;
use App\Enums\CalendarExceptionKind;
use App\Enums\ConstraintSeverity;
use App\Enums\ResourceType;
use App\Enums\ScheduleExceptionAction;
use App\Enums\ScheduleResourceRole;
use App\Enums\TimetableVersionStatus;
use App\Models\AcademicCalendar;
use App\Models\AcademicPeriod;
use App\Models\AcademicUnit;
use App\Models\AcademicUnitType;
use App\Models\AcademicYear;
use App\Models\CalendarException;
use App\Models\ConstraintConfiguration;
use App\Models\ConstraintDefinition;
use App\Models\FacultyProfile;
use App\Models\Feature;
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
use App\Scheduling\Constraints\OrganizationBlockedTimeConstraintHandler;
use App\Scheduling\Constraints\ResourceOverlapConstraintHandler;
use App\Scheduling\Constraints\RoomCapacityConstraintHandler;
use App\Scheduling\CreateScheduleEntry;
use App\Scheduling\ScheduleEntryData;
use App\Scheduling\ValidateScheduleEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->withOwnedOrganization()->create();
    $this->organization = $this->user->currentOrganization;
    grantManualSchedulingEntitlement($this->organization);

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
    AcademicCalendar::create([
        'organization_id' => $this->organization->id,
        'academic_period_id' => $this->period->id,
        'weekday' => 1,
        'starts_at_minute' => 420,
        'ends_at_minute' => 1200,
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
    DB::table('offering_instructors')->insert([
        'organization_id' => $this->organization->id,
        'offering_component_id' => $this->component->id,
        'faculty_profile_id' => FacultyProfile::query()
            ->where('scheduling_resource_id', $this->facultyResource->id)
            ->value('id'),
        'load_percentage' => 100,
        'is_primary' => true,
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

test('every scheduling mutation records an actor-scoped audit event', function () {
    $this->withHeaders(idempotencyHeaders())
        ->actingAs($this->user)
        ->postJson(route('scheduling.entries.store', $this->organization), validSchedulePayload($this))
        ->assertCreated();
    $entry = ScheduleEntry::query()->sole();

    $this->actingAs($this->user)
        ->patchJson(
            route('scheduling.entries.update', [$this->organization, $entry->public_id]),
            [
                'lock_version' => 1,
                'weekday' => 1,
                'starts_at_minute' => 600,
                'ends_at_minute' => 690,
                'resources' => validSchedulePayload($this)['resources'],
            ],
        )
        ->assertSuccessful();

    $this->actingAs($this->user)
        ->postJson(
            route('scheduling.exceptions.store', [$this->organization, $entry->public_id]),
            [
                'date' => '2026-08-24',
                'action' => ScheduleExceptionAction::Cancelled->value,
                'reason' => 'Campus holiday',
            ],
        )
        ->assertCreated();

    $this->actingAs($this->user)
        ->deleteJson(
            route('scheduling.entries.destroy', [$this->organization, $entry->public_id]),
            ['lock_version' => 2],
        )
        ->assertSuccessful();

    $events = DB::table('audit_events')
        ->where('organization_id', $this->organization->id)
        ->orderBy('id')
        ->get();

    expect($events->pluck('action')->all())->toBe([
        'scheduling.entry_created',
        'scheduling.entry_updated',
        'scheduling.exception_applied',
        'scheduling.entry_deleted',
    ])
        ->and($events->pluck('actor_user_id')->unique()->values()->all())->toBe([$this->user->id])
        ->and($events->pluck('subject_id')->filter()->unique()->values()->all())->toContain($entry->public_id);

    $created = json_decode((string) $events[0]->after, true, flags: JSON_THROW_ON_ERROR);
    $updatedBefore = json_decode((string) $events[1]->before, true, flags: JSON_THROW_ON_ERROR);
    $updatedAfter = json_decode((string) $events[1]->after, true, flags: JSON_THROW_ON_ERROR);
    $exceptionAfter = json_decode((string) $events[2]->after, true, flags: JSON_THROW_ON_ERROR);
    $deletedBefore = json_decode((string) $events[3]->before, true, flags: JSON_THROW_ON_ERROR);

    expect($created['id'])->toBe($entry->public_id)
        ->and($updatedBefore['starts_at_minute'])->toBe(480)
        ->and($updatedAfter['starts_at_minute'])->toBe(600)
        ->and($exceptionAfter['action'])->toBe(ScheduleExceptionAction::Cancelled->value)
        ->and($deletedBefore['lock_version'])->toBe(2);
});

test('manual scheduling entitlement gates every scheduling mutation', function () {
    $this->withHeaders(idempotencyHeaders())
        ->actingAs($this->user)
        ->postJson(route('scheduling.entries.store', $this->organization), validSchedulePayload($this))
        ->assertCreated();
    $entry = ScheduleEntry::query()->sole();

    DB::table('organization_subscriptions')
        ->where('organization_id', $this->organization->id)
        ->update(['period_ends_at' => now()->subMinute()]);

    $this->withHeaders(idempotencyHeaders())
        ->actingAs($this->user)
        ->postJson(route('scheduling.entries.store', $this->organization), validSchedulePayload($this, 600, 690))
        ->assertForbidden();
    $this->actingAs($this->user)
        ->patchJson(
            route('scheduling.entries.update', [$this->organization, $entry->public_id]),
            [
                'lock_version' => 1,
                'weekday' => 1,
                'starts_at_minute' => 600,
                'ends_at_minute' => 690,
                'resources' => validSchedulePayload($this)['resources'],
            ],
        )
        ->assertForbidden();
    $this->actingAs($this->user)
        ->postJson(
            route('scheduling.exceptions.store', [$this->organization, $entry->public_id]),
            [
                'date' => '2026-08-24',
                'action' => ScheduleExceptionAction::Cancelled->value,
            ],
        )
        ->assertForbidden();
    $this->actingAs($this->user)
        ->deleteJson(
            route('scheduling.entries.destroy', [$this->organization, $entry->public_id]),
            ['lock_version' => 1],
        )
        ->assertForbidden();

    $this->assertDatabaseCount('schedule_entries', 1);
    $this->assertDatabaseCount('audit_events', 1);
});

test('foreign schedule entries cannot be mutated through tenant-scoped routes', function () {
    $foreignEntry = ScheduleEntry::factory()->create();
    $payload = [
        'lock_version' => 1,
        'weekday' => 1,
        'starts_at_minute' => 600,
        'ends_at_minute' => 690,
        'resources' => validSchedulePayload($this)['resources'],
    ];

    $this->actingAs($this->user)
        ->patchJson(
            route('scheduling.entries.update', [$this->organization, $foreignEntry->public_id]),
            $payload,
        )
        ->assertNotFound();
    $this->actingAs($this->user)
        ->deleteJson(
            route('scheduling.entries.destroy', [$this->organization, $foreignEntry->public_id]),
            ['lock_version' => 1],
        )
        ->assertNotFound();
    $this->actingAs($this->user)
        ->postJson(
            route('scheduling.exceptions.store', [$this->organization, $foreignEntry->public_id]),
            [
                'date' => '2026-08-24',
                'action' => ScheduleExceptionAction::Cancelled->value,
            ],
        )
        ->assertNotFound();

    expect(ScheduleEntry::query()->whereKey($foreignEntry->getKey())->exists())->toBeTrue();
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
        ->assertJsonStructure([
            'error' => [
                'code',
                'message',
                'issues' => [
                    '*' => [
                        'code',
                        'severity',
                        'field',
                        'rule_code',
                        'message',
                        'details',
                        'resource',
                        'conflicting_entry_id',
                    ],
                ],
            ],
            'meta' => ['correlation_id'],
        ])
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

test('schedule validation rejects non-increasing local-time windows', function (
    int $startsAt,
    int $endsAt,
): void {
    $this->actingAs($this->user)
        ->postJson(
            route('scheduling.entries.validate', $this->organization),
            validSchedulePayload($this, $startsAt, $endsAt),
        )
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonFragment(['field' => 'ends_at_minute']);
})->with([
    'same-minute window' => [480, 480],
    'overnight window' => [1380, 60],
]);

test('schedule validation reports off-grid local-time windows', function (
    int $startsAt,
    int $endsAt,
): void {
    $this->actingAs($this->user)
        ->postJson(
            route('scheduling.entries.validate', $this->organization),
            validSchedulePayload($this, $startsAt, $endsAt),
        )
        ->assertSuccessful()
        ->assertJsonPath('data.attributes.valid', false)
        ->assertJsonFragment([
            'code' => 'invalid_granularity',
            'severity' => ConstraintSeverity::Hard->value,
        ]);
})->with([
    'off-grid start' => [485, 570],
    'off-grid end' => [480, 575],
]);

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

test('schedule entries can be moved and resized with optimistic locking', function () {
    $this->withHeaders(idempotencyHeaders())
        ->actingAs($this->user)
        ->postJson(route('scheduling.entries.store', $this->organization), validSchedulePayload($this))
        ->assertCreated();
    $entry = ScheduleEntry::query()->sole();

    $response = $this->actingAs($this->user)
        ->patchJson(
            route('scheduling.entries.update', [$this->organization, $entry->public_id]),
            [
                'lock_version' => 1,
                'weekday' => 1,
                'starts_at_minute' => 600,
                'ends_at_minute' => 690,
                'delivery_mode' => 'physical',
                'resources' => validSchedulePayload($this)['resources'],
            ],
        );

    $response->assertSuccessful()
        ->assertJsonPath('data.type', 'schedule_entry')
        ->assertJsonPath('data.attributes.starts_at_minute', 600)
        ->assertJsonPath('data.attributes.lock_version', 2);
    $this->assertDatabaseMissing('schedule_reservations', [
        'schedule_entry_id' => $entry->getKey(),
        'starts_at_minute' => 480,
    ]);
    $this->assertDatabaseHas('schedule_reservations', [
        'schedule_entry_id' => $entry->getKey(),
        'starts_at_minute' => 600,
        'ends_at_minute' => 690,
    ]);
});

test('stale schedule entry updates return the current lock version', function () {
    $this->withHeaders(idempotencyHeaders())
        ->actingAs($this->user)
        ->postJson(route('scheduling.entries.store', $this->organization), validSchedulePayload($this))
        ->assertCreated();
    $entry = ScheduleEntry::query()->sole();
    $payload = [
        'lock_version' => 1,
        'weekday' => 1,
        'starts_at_minute' => 600,
        'ends_at_minute' => 690,
        'resources' => validSchedulePayload($this)['resources'],
    ];

    $this->actingAs($this->user)
        ->patchJson(route('scheduling.entries.update', [$this->organization, $entry->public_id]), $payload)
        ->assertSuccessful();

    $this->actingAs($this->user)
        ->patchJson(route('scheduling.entries.update', [$this->organization, $entry->public_id]), $payload)
        ->assertConflict()
        ->assertJsonPath('error.code', 'stale_write')
        ->assertJsonPath('error.current.lock_version', 2);
});

test('schedule entries can be deleted with optimistic locking', function () {
    $this->withHeaders(idempotencyHeaders())
        ->actingAs($this->user)
        ->postJson(route('scheduling.entries.store', $this->organization), validSchedulePayload($this))
        ->assertCreated();
    $entry = ScheduleEntry::query()->sole();

    $this->actingAs($this->user)
        ->deleteJson(
            route('scheduling.entries.destroy', [$this->organization, $entry->public_id]),
            ['lock_version' => 1],
        )
        ->assertSuccessful()
        ->assertJsonPath('data.type', 'schedule_entry_deleted');

    $this->assertDatabaseCount('schedule_entries', 0);
    $this->assertDatabaseCount('schedule_entry_resources', 0);
    $this->assertDatabaseCount('schedule_reservations', 0);
});

test('dated schedule exceptions cancel and reschedule without mutating the recurring entry', function () {
    $this->withHeaders(idempotencyHeaders())
        ->actingAs($this->user)
        ->postJson(route('scheduling.entries.store', $this->organization), validSchedulePayload($this))
        ->assertCreated();
    $entry = ScheduleEntry::query()->sole();

    $cancelled = $this->actingAs($this->user)
        ->postJson(
            route('scheduling.exceptions.store', [$this->organization, $entry->public_id]),
            [
                'date' => '2026-08-24',
                'action' => ScheduleExceptionAction::Cancelled->value,
                'reason' => 'Campus holiday',
            ],
        );

    $cancelled->assertCreated()
        ->assertJsonPath('data.type', 'schedule_entry_exception')
        ->assertJsonPath('data.attributes.action', 'cancelled')
        ->assertJsonPath('data.attributes.starts_at_minute', null)
        ->assertJsonCount(0, 'data.attributes.resources');

    $rescheduled = $this->actingAs($this->user)
        ->postJson(
            route('scheduling.exceptions.store', [$this->organization, $entry->public_id]),
            [
                'date' => '2026-08-31',
                'action' => ScheduleExceptionAction::Rescheduled->value,
                'starts_at_minute' => 600,
                'ends_at_minute' => 690,
                'reason' => 'Make-up room time',
            ],
        );

    $rescheduled->assertCreated()
        ->assertJsonPath('data.attributes.action', 'rescheduled')
        ->assertJsonPath('data.attributes.starts_at_minute', 600)
        ->assertJsonCount(0, 'data.attributes.resources');
    expect($entry->fresh()->starts_at_minute)->toBe(480)
        ->and($entry->reservations()->count())->toBe(3)
        ->and($entry->exceptions()->count())->toBe(2);
});

test('dated replacement exceptions validate replacement teachers and rooms', function () {
    $replacementFacultyResource = SchedulingResource::create([
        'organization_id' => $this->organization->id,
        'type' => ResourceType::Faculty,
        'name' => 'Teacher B',
    ]);
    $replacementFaculty = FacultyProfile::create([
        'organization_id' => $this->organization->id,
        'scheduling_resource_id' => $replacementFacultyResource->id,
        'employee_number' => 'FAC-002',
    ]);
    DB::table('offering_instructors')->insert([
        'organization_id' => $this->organization->id,
        'offering_component_id' => $this->component->id,
        'faculty_profile_id' => $replacementFaculty->id,
        'load_percentage' => 100,
        'is_primary' => false,
    ]);
    $this->withHeaders(idempotencyHeaders())
        ->actingAs($this->user)
        ->postJson(route('scheduling.entries.store', $this->organization), validSchedulePayload($this))
        ->assertCreated();
    $entry = ScheduleEntry::query()->sole();

    $response = $this->actingAs($this->user)
        ->postJson(
            route('scheduling.exceptions.store', [$this->organization, $entry->public_id]),
            [
                'date' => '2026-09-07',
                'action' => ScheduleExceptionAction::Replaced->value,
                'resources' => [
                    ['resource_id' => $replacementFacultyResource->public_id, 'role' => ScheduleResourceRole::Instructor->value],
                    ['resource_id' => $this->group->resource->public_id, 'role' => ScheduleResourceRole::StudentGroup->value],
                    ['resource_id' => $this->roomResource->public_id, 'role' => ScheduleResourceRole::Room->value],
                ],
            ],
        );

    $response->assertCreated()
        ->assertJsonPath('data.attributes.action', 'replaced')
        ->assertJsonFragment(['resource_id' => $replacementFacultyResource->public_id]);
});

test('dated schedule exceptions require a recurring weekday and period date', function () {
    $this->withHeaders(idempotencyHeaders())
        ->actingAs($this->user)
        ->postJson(route('scheduling.entries.store', $this->organization), validSchedulePayload($this))
        ->assertCreated();
    $entry = ScheduleEntry::query()->sole();

    $this->actingAs($this->user)
        ->postJson(
            route('scheduling.exceptions.store', [$this->organization, $entry->public_id]),
            [
                'date' => '2026-08-25',
                'action' => ScheduleExceptionAction::Cancelled->value,
            ],
        )
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonFragment(['field' => 'date']);
});

test('soft availability preferences warn without invalidating or blocking scheduling', function () {
    ResourceAvailabilityRule::create([
        'organization_id' => $this->organization->id,
        'scheduling_resource_id' => $this->facultyResource->id,
        'academic_period_id' => null,
        'kind' => AvailabilityKind::Preferred,
        'weekday' => 1,
        'starts_at_minute' => 600,
        'ends_at_minute' => 720,
    ]);

    $validationResponse = $this->actingAs($this->user)
        ->postJson(route('scheduling.entries.validate', $this->organization), validSchedulePayload($this));

    $validationResponse->assertSuccessful()
        ->assertJsonPath('data.attributes.valid', true)
        ->assertJsonPath('data.attributes.hard_issues', [])
        ->assertJsonPath('data.attributes.score', 1)
        ->assertJsonFragment(['code' => 'resource_preference', 'severity' => 'soft']);
    expect($validationResponse->json('data.attributes.warnings'))->toHaveCount(1);

    $this->withHeaders(idempotencyHeaders())
        ->actingAs($this->user)
        ->postJson(route('scheduling.entries.store', $this->organization), validSchedulePayload($this))
        ->assertCreated();
});

test('hard available windows reject schedules outside the selected resource window', function () {
    ResourceAvailabilityRule::create([
        'organization_id' => $this->organization->id,
        'scheduling_resource_id' => $this->facultyResource->id,
        'academic_period_id' => $this->period->id,
        'kind' => AvailabilityKind::Available,
        'weekday' => 1,
        'starts_at_minute' => 600,
        'ends_at_minute' => 720,
    ]);

    $this->actingAs($this->user)
        ->postJson(route('scheduling.entries.validate', $this->organization), validSchedulePayload($this))
        ->assertSuccessful()
        ->assertJsonPath('data.attributes.valid', false)
        ->assertJsonStructure([
            'data' => [
                'type',
                'attributes' => [
                    'valid',
                    'issues' => [
                        '*' => [
                            'code',
                            'severity',
                            'field',
                            'rule_code',
                            'message',
                            'details',
                            'resource',
                            'conflicting_entry_id',
                        ],
                    ],
                    'hard_issues',
                    'warnings',
                    'score',
                ],
            ],
            'meta' => ['correlation_id'],
        ])
        ->assertJsonFragment(['code' => 'resource_unavailable', 'severity' => 'hard']);
});

test('calendar, room feature, and instructor eligibility checks return hard issues', function () {
    $feature = Feature::create([
        'organization_id' => $this->organization->id,
        'code' => 'projector',
        'name' => 'Projector',
    ]);
    DB::table('offering_component_features')->insert([
        'organization_id' => $this->organization->id,
        'offering_component_id' => $this->component->id,
        'feature_id' => $feature->id,
        'minimum_quantity' => 2,
    ]);
    $ineligibleResource = SchedulingResource::create([
        'organization_id' => $this->organization->id,
        'type' => ResourceType::Faculty,
        'name' => 'Teacher B',
    ]);
    FacultyProfile::create([
        'organization_id' => $this->organization->id,
        'scheduling_resource_id' => $ineligibleResource->id,
        'employee_number' => 'FAC-002',
    ]);
    $data = new ScheduleEntryData(
        timetableVersionId: $this->version->id,
        offeringComponentId: $this->component->id,
        weekday: 1,
        startsAtMinute: 480,
        endsAtMinute: 570,
        resources: [
            ['resource_id' => $ineligibleResource->id, 'role' => ScheduleResourceRole::Instructor->value],
            ['resource_id' => $this->group->resource->id, 'role' => ScheduleResourceRole::StudentGroup->value],
            ['resource_id' => $this->roomResource->id, 'role' => ScheduleResourceRole::Room->value],
        ],
    );

    $codes = collect(app(ValidateScheduleEntry::class)->handle($this->organization, $data))->pluck('code');

    expect($codes)
        ->toContain('instructor_eligibility')
        ->toContain('room_features_required');
});

test('calendar blocked dates and offering duration are hard constraints', function () {
    CalendarException::create([
        'organization_id' => $this->organization->id,
        'academic_period_id' => $this->period->id,
        'date' => '2026-08-24',
        'kind' => CalendarExceptionKind::Blocked,
        'name' => 'Campus maintenance',
    ]);
    $this->period->calendars()->where('weekday', 1)->update([
        'starts_at_minute' => 600,
        'ends_at_minute' => 1200,
    ]);
    $this->component->update(['duration_minutes' => 60]);

    $data = new ScheduleEntryData(
        timetableVersionId: $this->version->id,
        offeringComponentId: $this->component->id,
        weekday: 1,
        startsAtMinute: 480,
        endsAtMinute: 570,
        resources: [
            ['resource_id' => $this->facultyResource->id, 'role' => ScheduleResourceRole::Instructor->value],
            ['resource_id' => $this->group->resource->id, 'role' => ScheduleResourceRole::StudentGroup->value],
            ['resource_id' => $this->roomResource->id, 'role' => ScheduleResourceRole::Room->value],
        ],
    );
    $codes = collect(app(ValidateScheduleEntry::class)->handle($this->organization, $data))->pluck('code');

    expect($codes)
        ->toContain('calendar_operating_hours')
        ->toContain('calendar_blocked_date')
        ->toContain('offering_duration_mismatch');
});

test('date-aware validation applies the exact calendar exception and teaching window', function () {
    CalendarException::create([
        'organization_id' => $this->organization->id,
        'academic_period_id' => $this->period->id,
        'date' => '2026-08-24',
        'kind' => CalendarExceptionKind::Blocked,
        'name' => 'Campus maintenance',
    ]);
    CalendarException::create([
        'organization_id' => $this->organization->id,
        'academic_period_id' => $this->period->id,
        'date' => '2026-08-25',
        'kind' => CalendarExceptionKind::Teaching,
        'name' => 'Make-up class',
        'starts_at_minute' => 600,
        'ends_at_minute' => 720,
    ]);

    $makeData = function (int $weekday, CarbonImmutable $date, int $startsAtMinute = 480, int $endsAtMinute = 570): ScheduleEntryData {
        return new ScheduleEntryData(
            timetableVersionId: $this->version->getKey(),
            offeringComponentId: $this->component->getKey(),
            weekday: $weekday,
            startsAtMinute: $startsAtMinute,
            endsAtMinute: $endsAtMinute,
            resources: [
                ['resource_id' => $this->facultyResource->getKey(), 'role' => ScheduleResourceRole::Instructor->value],
                ['resource_id' => $this->group->resource->getKey(), 'role' => ScheduleResourceRole::StudentGroup->value],
                ['resource_id' => $this->roomResource->getKey(), 'role' => ScheduleResourceRole::Room->value],
            ],
            occurrenceDate: $date,
        );
    };

    $blockedDateIssues = collect(app(ValidateScheduleEntry::class)->handle(
        $this->organization,
        $makeData(1, CarbonImmutable::parse('2026-08-24')),
    ));
    $otherDateIssues = collect(app(ValidateScheduleEntry::class)->handle(
        $this->organization,
        $makeData(1, CarbonImmutable::parse('2026-08-31')),
    ));
    $teachingWindowIssues = collect(app(ValidateScheduleEntry::class)->handle(
        $this->organization,
        $makeData(2, CarbonImmutable::parse('2026-08-25')),
    ));
    $mismatchedDateIssues = collect(app(ValidateScheduleEntry::class)->handle(
        $this->organization,
        $makeData(2, CarbonImmutable::parse('2026-08-24')),
    ));

    expect($blockedDateIssues->pluck('code'))->toContain('calendar_blocked_date')
        ->and($otherDateIssues->pluck('code'))->not->toContain('calendar_blocked_date')
        ->and($teachingWindowIssues->pluck('code'))->toContain('calendar_exception_operating_hours')
        ->and($mismatchedDateIssues->pluck('code'))->toContain('occurrence_date_weekday_mismatch');
});

test('date-aware validation honors resource effective ranges', function () {
    ResourceAvailabilityRule::create([
        'organization_id' => $this->organization->id,
        'scheduling_resource_id' => $this->facultyResource->id,
        'academic_period_id' => $this->period->id,
        'kind' => AvailabilityKind::Available,
        'weekday' => 1,
        'starts_at_minute' => 600,
        'ends_at_minute' => 720,
        'effective_from' => '2026-08-24',
        'effective_until' => '2026-08-31',
    ]);

    $makeData = function (?CarbonImmutable $date): ScheduleEntryData {
        return new ScheduleEntryData(
            timetableVersionId: $this->version->getKey(),
            offeringComponentId: $this->component->getKey(),
            weekday: 1,
            startsAtMinute: 600,
            endsAtMinute: 690,
            resources: [
                ['resource_id' => $this->facultyResource->getKey(), 'role' => ScheduleResourceRole::Instructor->value],
                ['resource_id' => $this->group->resource->getKey(), 'role' => ScheduleResourceRole::StudentGroup->value],
                ['resource_id' => $this->roomResource->getKey(), 'role' => ScheduleResourceRole::Room->value],
            ],
            occurrenceDate: $date,
        );
    };

    $recurringIssues = collect(app(ValidateScheduleEntry::class)->handle($this->organization, $makeData(null)));
    $activeDateIssues = collect(app(ValidateScheduleEntry::class)->handle(
        $this->organization,
        $makeData(CarbonImmutable::parse('2026-08-24')),
    ));
    $inactiveDateIssues = collect(app(ValidateScheduleEntry::class)->handle(
        $this->organization,
        $makeData(CarbonImmutable::parse('2026-09-07')),
    ));

    expect($recurringIssues->pluck('code'))->toContain('resource_effective_date_range')
        ->and($activeDateIssues->pluck('code'))->not->toContain('resource_effective_date_range')
        ->and($inactiveDateIssues->pluck('code'))->toContain('resource_unavailable');
});

test('configured organization-wide blocked times reject matching schedule windows', function () {
    $definition = ConstraintDefinition::factory()->create([
        'code' => 'organization_blocked_time',
        'handler' => OrganizationBlockedTimeConstraintHandler::class,
        'default_severity' => ConstraintSeverity::Hard->value,
        'is_mandatory' => true,
    ]);
    ConstraintConfiguration::factory()->create([
        'organization_id' => $this->organization->getKey(),
        'constraint_definition_id' => $definition->getKey(),
        'severity' => ConstraintSeverity::Hard,
        'configuration' => [
            'blocked_times' => [[
                'weekday' => 1,
                'starts_at_minute' => 500,
                'ends_at_minute' => 550,
                'effective_from' => '2026-08-01',
                'effective_until' => '2026-08-31',
                'name' => 'Organization assembly',
            ]],
        ],
    ]);

    $data = new ScheduleEntryData(
        timetableVersionId: $this->version->getKey(),
        offeringComponentId: $this->component->getKey(),
        weekday: 1,
        startsAtMinute: 510,
        endsAtMinute: 540,
        resources: [
            ['resource_id' => $this->facultyResource->getKey(), 'role' => ScheduleResourceRole::Instructor->value],
            ['resource_id' => $this->group->resource->getKey(), 'role' => ScheduleResourceRole::StudentGroup->value],
            ['resource_id' => $this->roomResource->getKey(), 'role' => ScheduleResourceRole::Room->value],
        ],
        occurrenceDate: CarbonImmutable::parse('2026-08-24'),
    );

    expect(collect(app(ValidateScheduleEntry::class)->handle($this->organization, $data))
        ->pluck('code'))
        ->toContain('organization_blocked_time');
});

test('offering session and weekly minute limits prevent over-scheduling', function () {
    $data = new ScheduleEntryData(
        timetableVersionId: $this->version->id,
        offeringComponentId: $this->component->id,
        weekday: 1,
        startsAtMinute: 480,
        endsAtMinute: 570,
        resources: [
            ['resource_id' => $this->facultyResource->id, 'role' => ScheduleResourceRole::Instructor->value],
            ['resource_id' => $this->group->resource->id, 'role' => ScheduleResourceRole::StudentGroup->value],
            ['resource_id' => $this->roomResource->id, 'role' => ScheduleResourceRole::Room->value],
        ],
    );
    app(CreateScheduleEntry::class)->handle($this->organization, $data);

    $secondData = new ScheduleEntryData(
        timetableVersionId: $data->timetableVersionId,
        offeringComponentId: $data->offeringComponentId,
        weekday: 1,
        startsAtMinute: 600,
        endsAtMinute: 690,
        resources: $data->resources,
    );
    $codes = collect(app(ValidateScheduleEntry::class)->handle($this->organization, $secondData))->pluck('code');

    expect($codes)
        ->toContain('offering_session_limit')
        ->toContain('offering_weekly_minutes');
});

test('constraint registry honors stored configuration without weakening mandatory overlap', function () {
    $roomCapacityDefinition = ConstraintDefinition::factory()->create([
        'code' => 'room_capacity',
        'handler' => RoomCapacityConstraintHandler::class,
        'default_severity' => ConstraintSeverity::Hard->value,
        'is_mandatory' => false,
    ]);
    $overlapDefinition = ConstraintDefinition::factory()->create([
        'code' => 'resource_overlap',
        'handler' => ResourceOverlapConstraintHandler::class,
        'default_severity' => ConstraintSeverity::Hard->value,
        'is_mandatory' => true,
    ]);
    ConstraintConfiguration::factory()->create([
        'organization_id' => $this->organization->getKey(),
        'constraint_definition_id' => $roomCapacityDefinition->getKey(),
        'severity' => ConstraintSeverity::Soft,
    ]);
    ConstraintConfiguration::factory()->create([
        'organization_id' => $this->organization->getKey(),
        'constraint_definition_id' => $overlapDefinition->getKey(),
        'severity' => ConstraintSeverity::Soft,
    ]);
    $data = new ScheduleEntryData(
        timetableVersionId: $this->version->getKey(),
        offeringComponentId: $this->component->getKey(),
        weekday: 1,
        startsAtMinute: 480,
        endsAtMinute: 570,
        resources: [
            ['resource_id' => $this->facultyResource->getKey(), 'role' => ScheduleResourceRole::Instructor->value],
            ['resource_id' => $this->group->resource->getKey(), 'role' => ScheduleResourceRole::StudentGroup->value],
            ['resource_id' => $this->roomResource->getKey(), 'role' => ScheduleResourceRole::Room->value],
        ],
    );

    app(CreateScheduleEntry::class)->handle($this->organization, $data);
    $this->room->update(['capacity' => 20]);
    $issues = app(ValidateScheduleEntry::class)->handle($this->organization, $data);

    expect(collect($issues)->firstWhere('code', 'resource_overlap')['severity'])->toBe(ConstraintSeverity::Hard->value)
        ->and(collect($issues)->firstWhere('code', 'room_capacity')['severity'])->toBe(ConstraintSeverity::Soft->value);

    $roomCapacityConfiguration = ConstraintConfiguration::query()
        ->where('constraint_definition_id', $roomCapacityDefinition->getKey())
        ->firstOrFail();
    $roomCapacityConfiguration->update(['is_enabled' => false]);

    expect(collect(app(ValidateScheduleEntry::class)->handle($this->organization, $data))
        ->pluck('code'))
        ->not->toContain('room_capacity');
});
