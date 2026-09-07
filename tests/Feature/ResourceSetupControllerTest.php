<?php

use App\Enums\AcademicPeriodKind;
use App\Enums\AvailabilityKind;
use App\Enums\DeliveryMode;
use App\Enums\FacultyEmploymentType;
use App\Enums\OrganizationRole;
use App\Enums\SubjectComponentKind;
use App\Enums\SubjectOfferingStatus;
use App\Models\AcademicPeriod;
use App\Models\AcademicUnit;
use App\Models\AcademicUnitType;
use App\Models\AcademicYear;
use App\Models\FacultyProfile;
use App\Models\OfferingComponent;
use App\Models\Organization;
use App\Models\Room;
use App\Models\SchedulingResource;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('catalog managers can prepare an offering teaching team and activate it', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    $period = AcademicPeriod::factory()->forAcademicYear(AcademicYear::factory()->forOrganization($organization)->create())->create();
    $offering = SubjectOffering::factory()->forAcademicPeriod($period)->create(['status' => SubjectOfferingStatus::Draft]);
    $component = OfferingComponent::factory()->forOffering($offering)->create();
    $faculty = FacultyProfile::factory()->forOrganization($organization)->create();
    $data = ['offering_component_id' => $component->public_id, 'faculty_profile_id' => $faculty->public_id, 'load_percentage' => 100];
    $this->actingAs($owner)->post(route('catalog.offerings.instructors', $organization), $data)->assertSessionHasNoErrors()->assertRedirect();
    $this->post(route('catalog.offerings.status', $organization), ['offering_id' => $offering->public_id, 'status' => 'active'])->assertSessionHasNoErrors()->assertRedirect();
    expect($component->instructors()->sole()->is($faculty))->toBeTrue()
        ->and($offering->fresh()->status)->toBe(SubjectOfferingStatus::Active);
    $this->assertDatabaseHas('audit_events', ['organization_id' => $organization->id, 'action' => 'subject_offering.instructor_assigned']);
    $this->assertDatabaseHas('audit_events', ['organization_id' => $organization->id, 'action' => 'subject_offering.status_updated']);

    $this->get(route('resources.setup', $organization))->assertInertia(fn (Assert $page) => $page
        ->where('offerings.0.components.0.id', $component->public_id)
        ->where('offerings.0.components.0.instructors.0.name', $faculty->resource->name));

    $foreign = FacultyProfile::factory()->create();
    $this->post(route('catalog.offerings.instructors', $organization), [...$data, 'faculty_profile_id' => $foreign->public_id])->assertSessionHasErrors('faculty_profile_id');
    $this->post(route('catalog.offerings.instructors', $organization), [...$data, 'offering_component_id' => (string) $component->id])->assertSessionHasErrors('offering_component_id');
    $this->post(route('catalog.offerings.status', $organization), ['offering_id' => SubjectOffering::factory()->create()->public_id, 'status' => 'active'])->assertSessionHasErrors('offering_id');
    $viewer = User::factory()->create();
    $organization->members()->attach($viewer, ['role' => OrganizationRole::Member]);
    $this->actingAs($viewer)->post(route('catalog.offerings.instructors', $organization), $data)->assertForbidden();
    $this->post(route('catalog.offerings.status', $organization), ['offering_id' => $offering->public_id, 'status' => 'inactive'])->assertForbidden();
    expect($component->instructors()->count())->toBe(1)->and($offering->fresh()->status)->toBe(SubjectOfferingStatus::Active);
});

test('resource setup exposes public contracts and creates the dependency chain', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    $unitType = AcademicUnitType::factory()->forOrganization($organization)->create(['name' => 'Campus']);
    $unit = AcademicUnit::factory()->forType($unitType)->create(['name' => 'Main Campus']);
    $year = AcademicYear::factory()->forOrganization($organization)->create([
        'starts_on' => '2026-08-01',
        'ends_on' => '2027-07-31',
    ]);
    $period = AcademicPeriod::factory()->forAcademicYear($year)->create([
        'kind' => AcademicPeriodKind::Semester,
        'name' => 'First semester',
    ]);
    $group = StudentGroup::factory()
        ->forAcademicYear($year)
        ->forAcademicUnit($unit)
        ->create(['name' => 'BSCS 1A']);

    $this->actingAs($owner)
        ->get(route('resources.setup', $organization))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('resources/Setup')
            ->where('faculty', [])
            ->where('rooms', [])
            ->where('subjects', [])
            ->where('offerings', [])
            ->where('canManageResources', true)
            ->where('canManageCatalog', true));

    $this->actingAs($owner)
        ->post(route('resources.room-types.store', $organization), [
            'code' => 'LAB',
            'name' => 'Laboratory',
        ])
        ->assertRedirect(route('resources.setup', $organization));

    $this->actingAs($owner)
        ->post(route('resources.features.store', $organization), [
            'code' => 'PROJECTOR',
            'name' => 'Projector',
        ])
        ->assertRedirect(route('resources.setup', $organization));

    $this->actingAs($owner)
        ->post(route('resources.buildings.store', $organization), [
            'code' => 'SCI',
            'name' => 'Science Building',
            'campus_id' => $unit->public_id,
        ])
        ->assertRedirect(route('resources.setup', $organization));

    $this->actingAs($owner)
        ->post(route('resources.faculty.store', $organization), [
            'resource_name' => 'Dr. Ada Lovelace',
            'employee_number' => 'FAC-001',
            'position' => 'Professor',
            'employment_type' => FacultyEmploymentType::FullTime->value,
            'maximum_daily_minutes' => 480,
            'maximum_weekly_minutes' => 2400,
            'academic_unit_id' => $unit->public_id,
        ])
        ->assertRedirect(route('resources.setup', $organization));

    $this->actingAs($owner)
        ->post(route('resources.rooms.store', $organization), [
            'resource_name' => 'Science Lab 1',
            'code' => 'SCI-101',
            'name' => 'Science Laboratory 1',
            'room_type_code' => 'LAB',
            'building_code' => 'SCI',
            'capacity' => 32,
            'delivery_mode' => DeliveryMode::Physical->value,
        ])
        ->assertRedirect(route('resources.setup', $organization));

    $this->actingAs($owner)
        ->post(route('catalog.subjects.store', $organization), [
            'code' => 'CS-101',
            'name' => 'Programming I',
            'units' => 3,
        ])
        ->assertRedirect(route('resources.setup', $organization));

    $subject = Subject::query()->where('organization_id', $organization->getKey())->firstOrFail();

    $this->actingAs($owner)
        ->post(route('catalog.components.store', $organization), [
            'subject_id' => $subject->public_id,
            'kind' => SubjectComponentKind::Lecture->value,
            'name' => 'Lecture',
            'weekly_minutes' => 180,
            'sessions_per_week' => 2,
            'default_duration_minutes' => 90,
            'delivery_mode' => DeliveryMode::Physical->value,
            'room_type_code' => 'LAB',
            'feature_code' => 'PROJECTOR',
            'feature_minimum_quantity' => 1,
        ])
        ->assertRedirect(route('resources.setup', $organization));

    $this->actingAs($owner)
        ->post(route('catalog.offerings.store', $organization), [
            'period_id' => $period->public_id,
            'subject_id' => $subject->public_id,
            'student_group_id' => $group->public_id,
            'code' => 'CS-101-A',
            'expected_enrollment' => 30,
            'status' => SubjectOfferingStatus::Draft->value,
        ])
        ->assertRedirect(route('resources.setup', $organization));

    $profile = FacultyProfile::query()->where('organization_id', $organization->getKey())->firstOrFail();
    $room = Room::query()->where('organization_id', $organization->getKey())->firstOrFail();

    $this->actingAs($owner)
        ->post(route('resources.availability.store', $organization), [
            'resource_id' => $profile->resource->public_id,
            'period_id' => $period->public_id,
            'kind' => AvailabilityKind::Available->value,
            'weekday' => 1,
            'starts_at_minute' => 480,
            'ends_at_minute' => 1020,
            'priority' => 0,
        ])
        ->assertRedirect(route('resources.setup', $organization));

    expect($profile->academicUnits()->whereKey($unit->getKey())->exists())->toBeTrue()
        ->and($room->resource->public_id)->not->toBe((string) $room->resource->getKey())
        ->and(SubjectOffering::query()->where('organization_id', $organization->getKey())->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'room_type.created')->value('actor_user_id'))
        ->toBe($owner->getKey())
        ->and(DB::table('audit_events')->where('action', 'room_feature.created')->value('actor_user_id'))
        ->toBe($owner->getKey())
        ->and(DB::table('audit_events')->where('action', 'building.created')->value('actor_user_id'))
        ->toBe($owner->getKey())
        ->and(DB::table('audit_events')->where('action', 'room.created')->value('actor_user_id'))
        ->toBe($owner->getKey())
        ->and(DB::table('audit_events')->where('action', 'resource_availability_rule.created')->value('actor_user_id'))
        ->toBe($owner->getKey())
        ->and(DB::table('audit_events')->where('action', 'faculty_profile.created')->value('actor_user_id'))
        ->toBe($owner->getKey())
        ->and(DB::table('audit_events')->where('action', 'faculty_profile.academic_unit_assigned')->value('actor_user_id'))
        ->toBe($owner->getKey())
        ->and(DB::table('audit_events')->where('action', 'subject.created')->value('actor_user_id'))
        ->toBe($owner->getKey())
        ->and(DB::table('audit_events')->where('action', 'subject.component_created')->value('actor_user_id'))
        ->toBe($owner->getKey())
        ->and(DB::table('audit_events')->where('action', 'subject.component_requirements_saved')->value('actor_user_id'))
        ->toBe($owner->getKey())
        ->and(DB::table('audit_events')->where('action', 'subject_offering.created')->value('actor_user_id'))
        ->toBe($owner->getKey());

    $this->actingAs($owner)
        ->get(route('resources.setup', $organization))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('faculty.0.id', $profile->public_id)
            ->where('faculty.0.resource_id', $profile->resource->public_id)
            ->where('rooms.0.id', $room->public_id)
            ->where('subjects.0.id', $subject->public_id)
            ->where('offerings.0.components_count', 1));
});

test('resource setup mutations require resource or catalog permissions', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $member = User::factory()->create();
    $organization = $owner->currentOrganization;
    $organization->members()->attach($member, ['role' => 'member']);

    $this->actingAs($member)
        ->post(route('resources.faculty.store', $organization), [
            'resource_name' => 'Not allowed',
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('catalog.subjects.store', $organization), [
            'code' => 'NOPE',
            'name' => 'Not allowed',
        ])
        ->assertForbidden();
});

test('resource setup rejects foreign references and does not expose another organization', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();
    $organization = $owner->currentOrganization;
    $foreignOrganization = Organization::factory()->create();
    $foreignSubject = Subject::factory()->forOrganization($foreignOrganization)->create();
    $foreignResource = SchedulingResource::factory()->forOrganization($foreignOrganization)->create();

    $this->actingAs($owner)
        ->get(route('resources.setup', $organization))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('subjects', [])
            ->where('resources', []));

    $this->actingAs($owner)
        ->post(route('catalog.components.store', $organization), [
            'subject_id' => $foreignSubject->public_id,
            'kind' => SubjectComponentKind::Lecture->value,
            'name' => 'Foreign component',
            'weekly_minutes' => 180,
            'sessions_per_week' => 2,
            'default_duration_minutes' => 90,
            'delivery_mode' => DeliveryMode::Physical->value,
        ])
        ->assertSessionHasErrors('subject_id');

    $this->actingAs($owner)
        ->post(route('resources.availability.store', $organization), [
            'resource_id' => $foreignResource->public_id,
            'kind' => AvailabilityKind::Available->value,
            'weekday' => 1,
            'starts_at_minute' => 480,
            'ends_at_minute' => 1020,
        ])
        ->assertSessionHasErrors('resource_id');
});
