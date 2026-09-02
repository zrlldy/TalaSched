<?php

use App\Enums\AcademicPeriodKind;
use App\Enums\AvailabilityKind;
use App\Enums\FacultyEmploymentType;
use App\Enums\ResourceType;
use App\Models\AcademicPeriod;
use App\Models\AcademicUnit;
use App\Models\AcademicUnitType;
use App\Models\AcademicYear;
use App\Models\Organization;
use App\Resources\FacultyProfileService;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

test('faculty service manages profiles, department links, load limits, and preferences', function (): void {
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
    $department = AcademicUnit::factory()->forType($unitType)->create(['name' => 'Engineering']);
    $program = AcademicUnit::factory()->forType($unitType)->create(['name' => 'Computer Science']);
    $service = app(FacultyProfileService::class);

    $profile = $service->create($organization, 'Teacher A', [
        'employee_number' => 'FAC-001',
        'position' => 'Professor',
        'employment_type' => FacultyEmploymentType::FullTime,
        'maximum_daily_minutes' => 360,
        'maximum_weekly_minutes' => 1200,
    ]);

    $profile = $service->assignToAcademicUnit($organization, $profile, $department, true);
    $profile = $service->assignToAcademicUnit($organization, $profile, $program, true);
    $unavailable = $service->setAvailabilityRule(
        $organization,
        $profile,
        AvailabilityKind::Unavailable,
        1,
        480,
        600,
        $academicPeriod,
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-12-20'),
        10,
    );
    $preferred = $service->setAvailabilityRule(
        $organization,
        $profile,
        AvailabilityKind::Preferred,
        3,
        780,
        900,
    );
    $profile = $service->update($organization, $profile, [
        'position' => 'Senior Professor',
        'maximum_daily_minutes' => 420,
    ]);
    $programIsPrimary = DB::table('faculty_unit_assignments')
        ->where('faculty_profile_id', $profile->getKey())
        ->where('academic_unit_id', $program->getKey())
        ->value('is_primary');
    $departmentIsPrimary = DB::table('faculty_unit_assignments')
        ->where('faculty_profile_id', $profile->getKey())
        ->where('academic_unit_id', $department->getKey())
        ->value('is_primary');

    expect($profile->resource->type)->toBe(ResourceType::Faculty)
        ->and($profile->position)->toBe('Senior Professor')
        ->and($profile->maximum_daily_minutes)->toBe(420)
        ->and($profile->maximum_weekly_minutes)->toBe(1200)
        ->and($programIsPrimary)->toBe(1)
        ->and($departmentIsPrimary)->toBe(0)
        ->and($unavailable->resource->is($profile->resource))->toBeTrue()
        ->and($unavailable->academicPeriod->is($academicPeriod))->toBeTrue()
        ->and($unavailable->kind)->toBe(AvailabilityKind::Unavailable)
        ->and($preferred->kind)->toBe(AvailabilityKind::Preferred)
        ->and($preferred->academic_period_id)->toBeNull()
        ->and($unavailable->priority)->toBe(10)
        ->and(app(TenantContext::class)->organization())->toBeNull()
        ->and(DB::table('audit_events')->where('action', 'faculty_profile.created')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'faculty_profile.updated')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'faculty_profile.academic_unit_assigned')->count())->toBe(2)
        ->and(DB::table('audit_events')->where('action', 'faculty_profile.availability_rule_created')->count())->toBe(2);

    $service->removeAvailabilityRule($organization, $profile, $preferred);
    $service->removeFromAcademicUnit($organization, $profile, $program);

    expect($profile->fresh()->academicUnits)->toHaveCount(1)
        ->and($profile->fresh()->academicUnits->first()->is($department))->toBeTrue()
        ->and(DB::table('resource_availability_rules')->where('id', $preferred->getKey())->exists())->toBeFalse()
        ->and(DB::table('audit_events')->where('action', 'faculty_profile.academic_unit_removed')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'faculty_profile.availability_rule_removed')->count())->toBe(1);
});

test('faculty service rejects invalid limits and foreign tenant references', function (): void {
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $unitType = AcademicUnitType::factory()->forOrganization($organization)->create();
    $unit = AcademicUnit::factory()->forType($unitType)->create();
    $foreignUnitType = AcademicUnitType::factory()->forOrganization($foreignOrganization)->create();
    $foreignUnit = AcademicUnit::factory()->forType($foreignUnitType)->create();
    $academicYear = AcademicYear::factory()->forOrganization($organization)->create();
    $period = AcademicPeriod::factory()->forAcademicYear($academicYear)->create();
    $foreignYear = AcademicYear::factory()->forOrganization($foreignOrganization)->create();
    $foreignPeriod = AcademicPeriod::factory()->forAcademicYear($foreignYear)->create();
    $service = app(FacultyProfileService::class);
    $profile = $service->create($organization, 'Teacher A');

    expect(fn () => $service->create($organization, 'Teacher B', [
        'maximum_daily_minutes' => 600,
        'maximum_weekly_minutes' => 300,
    ]))->toThrow(ValidationException::class, 'weekly')
        ->and(fn () => $service->create($organization, ' ', []))->toThrow(ValidationException::class, 'name')
        ->and(fn () => $profile->update(['maximum_daily_minutes' => 0]))->toThrow(LogicException::class, 'positive')
        ->and(fn () => $service->assignToAcademicUnit($organization, $profile, $foreignUnit))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $service->setAvailabilityRule(
            $organization,
            $profile,
            AvailabilityKind::Available,
            1,
            480,
            600,
            $foreignPeriod,
        ))->toThrow(ModelNotFoundException::class)
        ->and($unit->organization_id)->toBe($organization->getKey())
        ->and($period->organization_id)->toBe($organization->getKey());
});
