<?php

use App\Academic\AcademicYearLifecycleService;
use App\Academic\StudentGroupService;
use App\Enums\AcademicPeriodKind;
use App\Models\AcademicPeriod;
use App\Models\AcademicUnit;
use App\Models\AcademicUnitType;
use App\Models\AcademicYear;
use App\Models\Organization;
use App\Models\StudentGroup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

test('student groups can be assigned, dated, and enrolled in matching periods idempotently', function (): void {
    $organization = Organization::factory()->create();
    $academicYear = AcademicYear::factory()->forOrganization($organization)->create([
        'starts_on' => '2026-08-01',
        'ends_on' => '2027-07-31',
    ]);
    $firstPeriod = AcademicPeriod::factory()->forAcademicYear($academicYear)->create([
        'kind' => AcademicPeriodKind::Term,
        'sequence' => 1,
        'starts_on' => '2026-08-01',
        'ends_on' => '2026-12-31',
    ]);
    $secondPeriod = AcademicPeriod::factory()->forAcademicYear($academicYear)->create([
        'kind' => AcademicPeriodKind::Term,
        'sequence' => 2,
        'starts_on' => '2027-01-01',
        'ends_on' => '2027-07-31',
    ]);
    $unitType = AcademicUnitType::factory()->forOrganization($organization)->create();
    $root = AcademicUnit::factory()->forType($unitType)->create(['code' => 'ROOT']);
    $child = AcademicUnit::factory()->forType($unitType)->create(['code' => 'CHILD', 'parent_id' => $root->getKey()]);
    $group = StudentGroup::factory()->forAcademicYear($academicYear)->forAcademicUnit($root)->create();
    $service = app(StudentGroupService::class);

    $datedGroup = $service->setActiveDates(
        $organization,
        $group,
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-12-31'),
    );
    $assignedGroup = $service->assignToUnit($organization, $datedGroup, $child);
    $enrolledGroup = $service->enrollInPeriod($organization, $assignedGroup, $firstPeriod);
    $service->enrollInPeriod($organization, $enrolledGroup, $firstPeriod);
    $enrolledGroup->load('periods');

    expect($assignedGroup->academic_unit_id)->toBe($child->getKey())
        ->and($enrolledGroup->active_from->toDateString())->toBe('2026-08-01')
        ->and($enrolledGroup->active_until->toDateString())->toBe('2026-12-31')
        ->and($enrolledGroup->periods->pluck('id')->all())->toEqual([$firstPeriod->getKey()])
        ->and(DB::table('student_group_periods')->where('student_group_id', $group->getKey())->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'student_group.active_dates_saved')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'student_group.academic_unit_assigned')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'student_group.period_enrolled')->count())->toBe(1);

    expect(fn () => $service->enrollInPeriod($organization, $enrolledGroup, $secondPeriod))
        ->toThrow(ValidationException::class);

    $unenrolledGroup = $service->unenrollFromPeriod($organization, $enrolledGroup, $firstPeriod);

    expect($unenrolledGroup->periods()->count())->toBe(0)
        ->and(DB::table('audit_events')->where('action', 'student_group.period_unenrolled')->count())->toBe(1);
});

test('student group service enforces date bounds, year ownership, and closed-year protection', function (): void {
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $academicYear = AcademicYear::factory()->forOrganization($organization)->create([
        'starts_on' => '2026-08-01',
        'ends_on' => '2027-07-31',
    ]);
    $period = AcademicPeriod::factory()->forAcademicYear($academicYear)->create([
        'starts_on' => '2026-08-01',
        'ends_on' => '2027-07-31',
    ]);
    $foreignYear = AcademicYear::factory()->forOrganization($foreignOrganization)->create();
    $foreignPeriod = AcademicPeriod::factory()->forAcademicYear($foreignYear)->create();
    $unitType = AcademicUnitType::factory()->forOrganization($organization)->create();
    $unit = AcademicUnit::factory()->forType($unitType)->create();
    $foreignUnitType = AcademicUnitType::factory()->forOrganization($foreignOrganization)->create();
    $foreignUnit = AcademicUnit::factory()->forType($foreignUnitType)->create();
    $group = StudentGroup::factory()->forAcademicYear($academicYear)->forAcademicUnit($unit)->create();
    $service = app(StudentGroupService::class);

    expect(fn () => $service->setActiveDates(
        $organization,
        $group,
        CarbonImmutable::parse('2027-08-01'),
        CarbonImmutable::parse('2027-08-15'),
    ))->toThrow(ValidationException::class)
        ->and(fn () => $service->setActiveDates(
            $organization,
            $group,
            CarbonImmutable::parse('2027-01-01'),
            CarbonImmutable::parse('2026-12-31'),
        ))->toThrow(ValidationException::class)
        ->and(fn () => $service->assignToUnit($organization, $group, $foreignUnit))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => $service->enrollInPeriod($organization, $group, $foreignPeriod))
        ->toThrow(ModelNotFoundException::class);

    expect($service->enrollInPeriod($organization, $group, $period))->toBeInstanceOf(StudentGroup::class);

    $lifecycle = app(AcademicYearLifecycleService::class);
    $closedYear = $lifecycle->close($organization, $lifecycle->activate($organization, $academicYear));

    expect($closedYear->status->value)->toBe('closed')
        ->and(fn () => $service->setActiveDates($organization, $group, null, null))
        ->toThrow(ValidationException::class);

    expect(fn () => StudentGroup::factory()->forAcademicYear($academicYear)->forAcademicUnit($unit)->create([
        'active_from' => '2026-07-31',
    ]))->toThrow(LogicException::class, 'fit within');
});
