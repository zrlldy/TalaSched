<?php

use App\Enums\AcademicPeriodKind;
use App\Enums\AvailabilityKind;
use App\Enums\DeliveryMode;
use App\Enums\FacultyEmploymentType;
use App\Enums\ResourceType;
use App\Enums\SubjectComponentKind;
use App\Models\AcademicPeriod;
use App\Models\AcademicUnit;
use App\Models\AcademicUnitType;
use App\Models\AcademicYear;
use App\Models\Building;
use App\Models\FacultyProfile;
use App\Models\Feature;
use App\Models\OfferingComponent;
use App\Models\Organization;
use App\Models\ResourceAvailabilityRule;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\SchedulingResource;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\SubjectComponent;
use App\Models\SubjectOffering;
use LogicException;

test('resource and catalog models expose a tenant-safe schedulable graph', function (): void {
    $organization = Organization::factory()->create();
    $academicYear = AcademicYear::factory()->forOrganization($organization)->create([
        'starts_on' => '2026-08-01',
        'ends_on' => '2027-07-31',
    ]);
    $academicPeriod = AcademicPeriod::factory()->forAcademicYear($academicYear)->create([
        'kind' => AcademicPeriodKind::Semester,
        'starts_on' => '2026-08-01',
        'ends_on' => '2026-12-20',
    ]);
    $unitType = AcademicUnitType::factory()->forOrganization($organization)->create();
    $campus = AcademicUnit::factory()->forType($unitType)->create();
    $studentGroup = StudentGroup::factory()
        ->forAcademicYear($academicYear)
        ->forAcademicUnit($campus)
        ->create();

    $facultyResource = SchedulingResource::factory()->forOrganization($organization)->faculty()->create();
    $faculty = FacultyProfile::factory()->forResource($facultyResource)->create([
        'employment_type' => FacultyEmploymentType::FullTime,
    ]);
    $roomType = RoomType::factory()->forOrganization($organization)->create();
    $building = Building::factory()->onCampus($campus)->create();
    $feature = Feature::factory()->forOrganization($organization)->create();
    $room = Room::factory()
        ->forOrganization($organization)
        ->inBuilding($building)
        ->ofType($roomType)
        ->create(['capacity' => 40, 'delivery_mode' => DeliveryMode::Physical]);
    $room->features()->attach($feature, [
        'organization_id' => $organization->getKey(),
        'quantity' => 1,
    ]);
    $availability = ResourceAvailabilityRule::factory()
        ->forResource($facultyResource)
        ->forPeriod($academicPeriod)
        ->create(['kind' => AvailabilityKind::Available]);

    $subject = Subject::factory()->forOrganization($organization)->create();
    $subjectComponent = SubjectComponent::factory()->forSubject($subject)->laboratory()->create();
    $subjectComponent->roomTypes()->attach($roomType, [
        'organization_id' => $organization->getKey(),
    ]);
    $subjectComponent->features()->attach($feature, [
        'organization_id' => $organization->getKey(),
        'minimum_quantity' => 1,
    ]);
    $offering = SubjectOffering::factory()
        ->forAcademicPeriod($academicPeriod)
        ->forSubject($subject)
        ->forStudentGroup($studentGroup)
        ->create(['owning_academic_unit_id' => $campus->getKey()]);
    $offeringComponent = OfferingComponent::factory()
        ->forOffering($offering)
        ->forSubjectComponent($subjectComponent)
        ->create(['required_room_type_id' => $roomType->getKey()]);
    $offeringComponent->features()->attach($feature, [
        'organization_id' => $organization->getKey(),
        'minimum_quantity' => 1,
    ]);
    $offeringComponent->instructors()->attach($faculty, [
        'organization_id' => $organization->getKey(),
        'load_percentage' => 100,
        'is_primary' => true,
    ]);

    $room->load(['building.campus', 'roomType', 'features']);
    $faculty->load(['resource', 'offeringComponents']);
    $subjectComponent->load(['subject', 'roomTypes', 'features']);
    $offering->load(['academicPeriod', 'subject', 'studentGroup', 'owningAcademicUnit', 'components']);
    $offeringComponent->load(['offering', 'subjectComponent', 'requiredRoomType', 'features', 'instructors']);

    expect($faculty->resource->type)->toBe(ResourceType::Faculty)
        ->and($faculty->employment_type)->toBe(FacultyEmploymentType::FullTime)
        ->and($room->resource->type)->toBe(ResourceType::Room)
        ->and($room->building->campus->is($campus))->toBeTrue()
        ->and($room->roomType->is($roomType))->toBeTrue()
        ->and($room->features->first()->is($feature))->toBeTrue()
        ->and($room->capacity)->toBe(40)
        ->and($availability->academicPeriod->is($academicPeriod))->toBeTrue()
        ->and($availability->kind)->toBe(AvailabilityKind::Available)
        ->and($subjectComponent->kind)->toBe(SubjectComponentKind::Laboratory)
        ->and($subjectComponent->roomTypes->first()->is($roomType))->toBeTrue()
        ->and($subjectComponent->features->first()->is($feature))->toBeTrue()
        ->and($offering->academicPeriod->is($academicPeriod))->toBeTrue()
        ->and($offering->subject->is($subject))->toBeTrue()
        ->and($offering->studentGroup->is($studentGroup))->toBeTrue()
        ->and($offering->owningAcademicUnit->is($campus))->toBeTrue()
        ->and($offeringComponent->offering->is($offering))->toBeTrue()
        ->and($offeringComponent->subjectComponent->is($subjectComponent))->toBeTrue()
        ->and($offeringComponent->requiredRoomType->is($roomType))->toBeTrue()
        ->and($offeringComponent->features->first()->is($feature))->toBeTrue()
        ->and($offeringComponent->instructors->first()->is($faculty))->toBeTrue();

    $faculty->delete();
    $room->delete();
    $subject->delete();

    expect($faculty->fresh()->trashed())->toBeTrue()
        ->and($room->fresh()->trashed())->toBeTrue()
        ->and($subject->fresh()->trashed())->toBeTrue();
});

test('resource and catalog models reject cross-tenant parent references', function (): void {
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $resource = SchedulingResource::factory()->forOrganization($organization)->faculty()->create();
    $academicYear = AcademicYear::factory()->forOrganization($organization)->create();
    $academicPeriod = AcademicPeriod::factory()->forAcademicYear($academicYear)->create();
    $unitType = AcademicUnitType::factory()->forOrganization($organization)->create();
    $unit = AcademicUnit::factory()->forType($unitType)->create();
    $group = StudentGroup::factory()->forAcademicYear($academicYear)->forAcademicUnit($unit)->create();
    $subject = Subject::factory()->forOrganization($organization)->create();
    $subjectComponent = SubjectComponent::factory()->forSubject($subject)->create();
    $roomType = RoomType::factory()->forOrganization($organization)->create();
    $offering = SubjectOffering::factory()
        ->forAcademicPeriod($academicPeriod)
        ->forSubject($subject)
        ->forStudentGroup($group)
        ->create();

    expect(fn () => FacultyProfile::factory()->forResource($resource)->create([
        'organization_id' => $foreignOrganization->getKey(),
    ]))->toThrow(LogicException::class, 'same organization')
        ->and(fn () => Building::factory()->onCampus($unit)->create([
            'organization_id' => $foreignOrganization->getKey(),
        ]))->toThrow(LogicException::class, 'same organization')
        ->and(fn () => ResourceAvailabilityRule::factory()
            ->forResource($resource)
            ->forPeriod($academicPeriod)
            ->create(['organization_id' => $foreignOrganization->getKey()]))
        ->toThrow(LogicException::class, 'same organization')
        ->and(fn () => SubjectOffering::factory()
            ->forAcademicPeriod($academicPeriod)
            ->forSubject($subject)
            ->forStudentGroup($group)
            ->create(['organization_id' => $foreignOrganization->getKey()]))
        ->toThrow(LogicException::class, 'same organization')
        ->and(fn () => OfferingComponent::factory()
            ->forOffering($offering)
            ->forSubjectComponent($subjectComponent)
            ->create([
                'organization_id' => $foreignOrganization->getKey(),
                'required_room_type_id' => $roomType->getKey(),
            ]))
        ->toThrow(LogicException::class, 'same organization');
});
