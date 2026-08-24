<?php

use App\Enums\AcademicPeriodKind;
use App\Models\AcademicPeriod;
use App\Models\AcademicUnit;
use App\Models\AcademicUnitClosure;
use App\Models\AcademicUnitType;
use App\Models\AcademicYear;
use App\Models\Organization;
use App\Models\StudentGroup;
use Illuminate\Database\QueryException;
use LogicException;

test('academic models expose tenant-safe relationships and casts', function (): void {
    $organization = Organization::factory()->create();
    $academicYear = AcademicYear::factory()->forOrganization($organization)->create([
        'starts_on' => '2026-08-01',
        'ends_on' => '2027-07-31',
    ]);
    $academicPeriod = AcademicPeriod::factory()->forAcademicYear($academicYear)->create([
        'kind' => AcademicPeriodKind::Semester,
        'sequence' => 1,
        'starts_on' => '2026-08-01',
        'ends_on' => '2026-12-20',
    ]);
    $unitType = AcademicUnitType::factory()->forOrganization($organization)->create([
        'display_order' => 3,
    ]);
    $root = AcademicUnit::factory()->forType($unitType)->create(['code' => 'ROOT']);
    $child = AcademicUnit::factory()->forType($unitType)->under($root)->create(['code' => 'CHILD']);
    $studentGroup = StudentGroup::factory()
        ->forAcademicYear($academicYear)
        ->forAcademicUnit($child)
        ->create(['expected_headcount' => '24']);

    $academicPeriod->studentGroups()->attach($studentGroup, [
        'organization_id' => $organization->getKey(),
    ]);
    AcademicUnitClosure::query()->insert([
        ['organization_id' => $organization->getKey(), 'ancestor_id' => $root->getKey(), 'descendant_id' => $root->getKey(), 'depth' => 0],
        ['organization_id' => $organization->getKey(), 'ancestor_id' => $root->getKey(), 'descendant_id' => $child->getKey(), 'depth' => 1],
    ]);

    $academicYear->load(['periods', 'studentGroups']);
    $academicPeriod->load('studentGroups');
    $studentGroup->load(['academicYear', 'academicUnit', 'periods']);
    $root->load('descendants');
    $child->load('ancestors');

    expect($academicYear->periods->first()->is($academicPeriod))->toBeTrue()
        ->and($academicYear->studentGroups->first()->is($studentGroup))->toBeTrue()
        ->and($academicPeriod->studentGroups->first()->is($studentGroup))->toBeTrue()
        ->and($studentGroup->academicYear->is($academicYear))->toBeTrue()
        ->and($studentGroup->academicUnit->is($child))->toBeTrue()
        ->and($studentGroup->periods->first()->is($academicPeriod))->toBeTrue()
        ->and($root->descendants->contains(fn (AcademicUnit $unit): bool => $unit->is($child)))->toBeTrue()
        ->and($child->ancestors->contains(fn (AcademicUnit $unit): bool => $unit->is($root)))->toBeTrue()
        ->and($academicPeriod->kind)->toBe(AcademicPeriodKind::Semester)
        ->and($academicPeriod->sequence)->toBeInt()
        ->and($unitType->fresh()->display_order)->toBeInt()
        ->and($studentGroup->expected_headcount)->toBeInt()
        ->and($child->ancestors->first()->pivot->depth)->toBeInt();
});

test('academic models reject cross-tenant references and ownership changes', function (): void {
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $academicYear = AcademicYear::factory()->forOrganization($organization)->create();
    $foreignUnitType = AcademicUnitType::factory()->forOrganization($foreignOrganization)->create();

    expect(fn () => AcademicPeriod::factory()
        ->forAcademicYear($academicYear)
        ->create(['organization_id' => $foreignOrganization->getKey()]))
        ->toThrow(LogicException::class, 'same organization')
        ->and(fn () => AcademicUnit::factory()
            ->forType($foreignUnitType)
            ->create(['organization_id' => $organization->getKey()]))
        ->toThrow(LogicException::class, 'same organization');

    expect(fn () => $academicYear->update(['organization_id' => $foreignOrganization->getKey()]))
        ->toThrow(LogicException::class, 'cannot be changed');
});

test('academic date ranges are validated before persistence', function (): void {
    $organization = Organization::factory()->create();
    $academicYear = AcademicYear::factory()->forOrganization($organization)->create();

    expect(fn () => AcademicYear::factory()->forOrganization($organization)->create([
        'starts_on' => '2027-08-01',
        'ends_on' => '2027-07-31',
    ]))->toThrow(LogicException::class, 'academic year')
        ->and(fn () => AcademicPeriod::factory()->forAcademicYear($academicYear)->create([
            'starts_on' => $academicYear->starts_on->subDay(),
        ]))->toThrow(LogicException::class, 'fit within')
        ->and(fn () => AcademicUnit::factory()->create([
            'active_from' => '2027-08-01',
            'active_until' => '2027-07-31',
        ]))->toThrow(LogicException::class, 'active start date');
});

test('academic units and student groups preserve their deletion rules', function (): void {
    $organization = Organization::factory()->create();
    $unitType = AcademicUnitType::factory()->forOrganization($organization)->create();
    $root = AcademicUnit::factory()->forType($unitType)->create();
    $child = AcademicUnit::factory()->forType($unitType)->under($root)->create();
    $studentGroup = StudentGroup::factory()->forOrganization($organization)->create();

    expect(fn () => $root->forceDelete())->toThrow(QueryException::class)
        ->and(fn () => $unitType->delete())->toThrow(QueryException::class);

    $child->delete();
    $studentGroup->delete();

    expect($child->fresh()->trashed())->toBeTrue()
        ->and($studentGroup->fresh()->trashed())->toBeTrue();
});
