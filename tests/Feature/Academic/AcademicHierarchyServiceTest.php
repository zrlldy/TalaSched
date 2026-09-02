<?php

use App\Academic\AcademicHierarchyService;
use App\Models\AcademicUnit;
use App\Models\AcademicUnitType;
use App\Models\AcademicYear;
use App\Models\Organization;
use App\Models\StudentGroup;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

test('the hierarchy service supports arbitrary configured chains without preset assumptions', function (): void {
    $organization = Organization::factory()->create();
    $types = collect(['campus', 'faculty', 'program', 'cohort'])
        ->mapWithKeys(fn (string $code): array => [
            $code => AcademicUnitType::factory()->forOrganization($organization)->create(['code' => $code]),
        ]);

    DB::table('academic_unit_type_edges')->insert([
        ['organization_id' => $organization->getKey(), 'parent_type_id' => $types['campus']->getKey(), 'child_type_id' => $types['faculty']->getKey()],
        ['organization_id' => $organization->getKey(), 'parent_type_id' => $types['faculty']->getKey(), 'child_type_id' => $types['program']->getKey()],
        ['organization_id' => $organization->getKey(), 'parent_type_id' => $types['program']->getKey(), 'child_type_id' => $types['cohort']->getKey()],
    ]);

    $service = app(AcademicHierarchyService::class);
    $campus = $service->create($organization, $types['campus'], 'Campus', 'CAMPUS');
    $faculty = $service->create($organization, $types['faculty'], 'Faculty', 'FACULTY', $campus);
    $program = $service->create($organization, $types['program'], 'Program', 'PROGRAM', $faculty);
    $cohort = $service->create($organization, $types['cohort'], 'Cohort', 'COHORT', $program);

    DB::enableQueryLog();

    try {
        DB::flushQueryLog();
        $descendants = $service->query($organization, $campus->public_id);
        $traversalQueryCount = count(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
    }

    expect($descendants->pluck('pivot.depth')->all())->toEqual([0, 1, 2, 3])
        ->and(DB::table('academic_unit_closure')->where('ancestor_id', $campus->getKey())->count())->toBe(4)
        ->and($cohort->parent_id)->toBe($program->getKey())
        ->and($traversalQueryCount)->toBe(2);
});

test('the hierarchy service creates closure rows and depth-aware root queries', function (): void {
    $organization = Organization::factory()->create();
    $campusType = AcademicUnitType::factory()->forOrganization($organization)->create(['code' => 'campus']);
    $departmentType = AcademicUnitType::factory()->forOrganization($organization)->create(['code' => 'department']);

    DB::table('academic_unit_type_edges')->insert([
        [
            'organization_id' => $organization->getKey(),
            'parent_type_id' => $campusType->getKey(),
            'child_type_id' => $departmentType->getKey(),
        ],
        [
            'organization_id' => $organization->getKey(),
            'parent_type_id' => $departmentType->getKey(),
            'child_type_id' => $departmentType->getKey(),
        ],
    ]);

    $service = app(AcademicHierarchyService::class);
    $campus = $service->create($organization, $campusType, 'Main Campus', 'MAIN');
    $department = $service->create($organization, $departmentType, 'Science', 'SCI', $campus);
    $faculty = $service->create($organization, $departmentType, 'Faculty of Science', 'SCI-FACULTY', $department);

    $closureRows = DB::table('academic_unit_closure')
        ->where('organization_id', $organization->getKey())
        ->orderBy('ancestor_id')
        ->orderBy('descendant_id')
        ->get(['ancestor_id', 'descendant_id', 'depth']);
    $descendants = $service->query($organization, $campus->public_id);

    expect($closureRows)->toHaveCount(6)
        ->and($closureRows->firstWhere('ancestor_id', $campus->getKey())->depth)->toBe(0)
        ->and($closureRows->firstWhere('ancestor_id', $department->getKey())->depth)->toBe(0)
        ->and($descendants->pluck('id')->all())->toEqual([$campus->id, $department->id, $faculty->id])
        ->and($descendants->pluck('pivot.depth')->all())->toEqual([0, 1, 2]);
});

test('the hierarchy service enforces tenant ownership and configured type edges', function (): void {
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $rootType = AcademicUnitType::factory()->forOrganization($organization)->create();
    $foreignType = AcademicUnitType::factory()->forOrganization($foreignOrganization)->create();
    $service = app(AcademicHierarchyService::class);

    expect(fn () => $service->create($organization, $foreignType, 'Foreign unit'))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => $service->create($organization, $rootType, 'Root', parent: AcademicUnit::factory()->forOrganization($foreignOrganization)->create()))
        ->toThrow(ModelNotFoundException::class);

    $root = $service->create($organization, $rootType, 'Root');

    expect(fn () => $service->create($organization, $rootType, 'Nested root', parent: $root))
        ->toThrow(ValidationException::class)
        ->and(fn () => $service->query($foreignOrganization, $root->public_id))
        ->toThrow(ModelNotFoundException::class);
});

test('moving a subtree rewrites closure paths and rejects descendant cycles', function (): void {
    $organization = Organization::factory()->create();
    $rootType = AcademicUnitType::factory()->forOrganization($organization)->create();
    $branchType = AcademicUnitType::factory()->forOrganization($organization)->create();
    DB::table('academic_unit_type_edges')->insert([
        [
            'organization_id' => $organization->getKey(),
            'parent_type_id' => $rootType->getKey(),
            'child_type_id' => $branchType->getKey(),
        ],
        [
            'organization_id' => $organization->getKey(),
            'parent_type_id' => $branchType->getKey(),
            'child_type_id' => $branchType->getKey(),
        ],
    ]);
    $service = app(AcademicHierarchyService::class);
    $firstRoot = $service->create($organization, $rootType, 'First root');
    $secondRoot = $service->create($organization, $rootType, 'Second root');
    $branch = $service->create($organization, $branchType, 'Branch', parent: $firstRoot);
    $leaf = $service->create($organization, $branchType, 'Leaf', parent: $branch);

    $service->move($organization, $branch, $secondRoot);

    expect($branch->fresh()->parent_id)->toBe($secondRoot->getKey())
        ->and(DB::table('academic_unit_closure')->where('ancestor_id', $firstRoot->getKey())->where('descendant_id', $branch->getKey())->exists())->toBeFalse()
        ->and(DB::table('academic_unit_closure')->where('ancestor_id', $secondRoot->getKey())->where('descendant_id', $branch->getKey())->value('depth'))->toBe(1)
        ->and(DB::table('academic_unit_closure')->where('ancestor_id', $secondRoot->getKey())->where('descendant_id', $leaf->getKey())->value('depth'))->toBe(2)
        ->and(DB::table('audit_events')->where('action', 'academic_unit.moved')->count())->toBe(1)
        ->and(fn () => $service->move($organization, $secondRoot, $leaf))->toThrow(ValidationException::class);
});

test('archiving requires a leaf without active student groups and hides it from default queries', function (): void {
    $organization = Organization::factory()->create();
    $rootType = AcademicUnitType::factory()->forOrganization($organization)->create();
    $branchType = AcademicUnitType::factory()->forOrganization($organization)->create();
    DB::table('academic_unit_type_edges')->insert([
        'organization_id' => $organization->getKey(),
        'parent_type_id' => $rootType->getKey(),
        'child_type_id' => $branchType->getKey(),
    ]);
    $service = app(AcademicHierarchyService::class);
    $root = $service->create($organization, $rootType, 'Root');
    $branch = $service->create($organization, $branchType, 'Branch', parent: $root);
    $year = AcademicYear::factory()->forOrganization($organization)->create();
    $studentGroup = StudentGroup::factory()->forAcademicYear($year)->forAcademicUnit($branch)->create();

    expect(fn () => $service->archive($organization, $root))
        ->toThrow(ValidationException::class);

    $studentGroup->delete();
    $archived = $service->archive($organization, $branch);

    expect($archived->trashed())->toBeTrue()
        ->and($service->query($organization, $root->public_id)->pluck('id')->all())->toEqual([$root->id])
        ->and($service->query($organization, $root->public_id, includeArchived: true)->pluck('id')->all())->toEqual([$root->id, $branch->id])
        ->and(DB::table('audit_events')->where('action', 'academic_unit.archived')->count())->toBe(1);
});
