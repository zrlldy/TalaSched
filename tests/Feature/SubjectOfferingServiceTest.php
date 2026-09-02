<?php

use App\Catalog\SubjectCatalogService;
use App\Catalog\SubjectOfferingService;
use App\Enums\AcademicPeriodKind;
use App\Enums\DeliveryMode;
use App\Enums\ResourceType;
use App\Enums\SubjectComponentKind;
use App\Enums\SubjectOfferingStatus;
use App\Models\AcademicPeriod;
use App\Models\AcademicUnit;
use App\Models\AcademicUnitType;
use App\Models\AcademicYear;
use App\Models\FacultyProfile;
use App\Models\Feature;
use App\Models\OfferingComponent;
use App\Models\Organization;
use App\Models\RoomType;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\SubjectComponent;
use App\Models\SubjectOffering;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

test('offering service snapshots subject components and manages eligible instructors', function (): void {
    $organization = Organization::factory()->create();
    $year = AcademicYear::factory()->forOrganization($organization)->create();
    $period = AcademicPeriod::factory()->forAcademicYear($year)->create([
        'kind' => AcademicPeriodKind::Term,
    ]);
    $unitType = AcademicUnitType::factory()->forOrganization($organization)->create();
    $unit = AcademicUnit::factory()->forType($unitType)->create();
    $group = StudentGroup::factory()->forAcademicYear($year)->forAcademicUnit($unit)->create();
    $roomType = RoomType::factory()->forOrganization($organization)->create();
    $feature = Feature::factory()->forOrganization($organization)->create();
    $catalog = app(SubjectCatalogService::class);
    $offerings = app(SubjectOfferingService::class);
    $subject = $catalog->createSubject($organization, 'SCI101', 'General Science', 3.0);
    $lecture = $catalog->addComponent(
        $organization,
        $subject,
        SubjectComponentKind::Lecture,
        'Lecture',
        180,
        2,
        90,
        30,
        DeliveryMode::Physical,
    );
    $lab = $catalog->addComponent(
        $organization,
        $subject,
        SubjectComponentKind::Laboratory,
        'Laboratory',
        120,
        1,
        120,
        20,
        DeliveryMode::Physical,
    );
    $catalog->setRequirements($organization, $lecture, [$roomType], [[
        'feature' => $feature,
        'minimum_quantity' => 1,
    ]]);
    $faculty = FacultyProfile::factory()->forOrganization($organization)->create();
    $secondFaculty = FacultyProfile::factory()->forOrganization($organization)->create();

    $offering = $offerings->createOffering(
        $organization,
        $period,
        $subject,
        $group,
        'SCI101-G1',
        28,
    );
    $components = $offering->components->sortBy('subject_component_id')->values();
    $firstComponent = $components->first();
    $offerings->assignInstructor($organization, $firstComponent, $faculty, 60, true);
    $offerings->assignInstructor($organization, $firstComponent, $secondFaculty, 40, true);
    $offerings->updateStatus($organization, $offering, SubjectOfferingStatus::Active);
    $lecture->update(['weekly_minutes' => 240]);

    $primaryAssignments = DB::table('offering_instructors')
        ->where('offering_component_id', $firstComponent->getKey())
        ->orderBy('faculty_profile_id')
        ->pluck('is_primary')
        ->all();
    $offering = $offering->fresh(['components', 'subject', 'studentGroup', 'academicPeriod']);
    $snapshotLecture = $offering->components->firstWhere('subject_component_id', $lecture->getKey());

    expect($offering->status)->toBe(SubjectOfferingStatus::Active)
        ->and($offering->expected_enrollment)->toBe(28)
        ->and($offering->academicPeriod->is($period))->toBeTrue()
        ->and($offering->studentGroup->is($group))->toBeTrue()
        ->and($offering->components)->toHaveCount(2)
        ->and($snapshotLecture->weekly_minutes)->toBe(180)
        ->and($snapshotLecture->duration_minutes)->toBe(90)
        ->and($snapshotLecture->required_room_type_id)->toBe($roomType->getKey())
        ->and(DB::table('offering_component_features')
            ->where('offering_component_id', $snapshotLecture->getKey())
            ->value('feature_id'))->toBe($feature->getKey())
        ->and($primaryAssignments)->toBe([0, 1])
        ->and(DB::table('audit_events')->where('action', 'subject_offering.created')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'subject_offering.instructor_assigned')->count())->toBe(2)
        ->and(DB::table('audit_events')->where('action', 'subject_offering.status_updated')->count())->toBe(1);

    $offerings->removeInstructor($organization, $firstComponent, $secondFaculty);

    expect(DB::table('offering_instructors')
        ->where('offering_component_id', $firstComponent->getKey())
        ->where('faculty_profile_id', $secondFaculty->getKey())
        ->exists())->toBeFalse()
        ->and(DB::table('audit_events')->where('action', 'subject_offering.instructor_removed')->count())->toBe(1);
});

test('offering service rejects mismatched academic years and foreign instructors', function (): void {
    $organization = Organization::factory()->create();
    $firstYear = AcademicYear::factory()->forOrganization($organization)->create();
    $secondYear = AcademicYear::factory()->forOrganization($organization)->create([
        'name' => 'Second Academic Year',
        'starts_on' => $firstYear->ends_on->addDay(),
        'ends_on' => $firstYear->ends_on->addYear(),
    ]);
    $firstPeriod = AcademicPeriod::factory()->forAcademicYear($firstYear)->create();
    $secondPeriod = AcademicPeriod::factory()->forAcademicYear($secondYear)->create();
    $unitType = AcademicUnitType::factory()->forOrganization($organization)->create();
    $unit = AcademicUnit::factory()->forType($unitType)->create();
    $group = StudentGroup::factory()->forAcademicYear($firstYear)->forAcademicUnit($unit)->create();
    $subject = Subject::factory()->forOrganization($organization)->create();
    $component = SubjectComponent::factory()->forSubject($subject)->create();
    $offerings = app(SubjectOfferingService::class);
    $foreignOrganization = Organization::factory()->create();
    $foreignFaculty = FacultyProfile::factory()->forOrganization($foreignOrganization)->create();
    $validOffering = $offerings->createOffering($organization, $firstPeriod, $subject, $group);
    $validComponent = $validOffering->components->first();

    expect(fn () => $offerings->createOffering($organization, $secondPeriod, $subject, $group))
        ->toThrow(ValidationException::class, 'academic year')
        ->and(fn () => $offerings->assignInstructor($organization, $validComponent, $foreignFaculty))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => $offerings->assignInstructor($organization, $validComponent, FacultyProfile::factory()->forOrganization($organization)->create(), 101))
        ->toThrow(ValidationException::class, 'between')
        ->and(fn () => SubjectOffering::factory()->forAcademicPeriod($firstPeriod)->forSubject($subject)->forStudentGroup($group)->create([
            'organization_id' => $organization->getKey(),
        ]))->toBeTruthy()
        ->and($component->organization_id)->toBe($organization->getKey())
        ->and($foreignFaculty->resource->type)->toBe(ResourceType::Faculty);
});

test('offering component snapshots cannot reference another subject', function (): void {
    $organization = Organization::factory()->create();
    $year = AcademicYear::factory()->forOrganization($organization)->create();
    $period = AcademicPeriod::factory()->forAcademicYear($year)->create();
    $unitType = AcademicUnitType::factory()->forOrganization($organization)->create();
    $unit = AcademicUnit::factory()->forType($unitType)->create();
    $group = StudentGroup::factory()->forAcademicYear($year)->forAcademicUnit($unit)->create();
    $subject = Subject::factory()->forOrganization($organization)->create();
    $otherSubject = Subject::factory()->forOrganization($organization)->create();
    $offering = SubjectOffering::factory()->forAcademicPeriod($period)->forSubject($subject)->forStudentGroup($group)->create();
    $otherComponent = SubjectComponent::factory()->forSubject($otherSubject)->create();

    expect(fn () => OfferingComponent::factory()
        ->forOffering($offering)
        ->forSubjectComponent($otherComponent)
        ->create())->toThrow(LogicException::class, 'offering subject');
});
