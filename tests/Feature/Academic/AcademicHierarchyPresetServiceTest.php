<?php

use App\Academic\AcademicHierarchyPresetService;
use App\Enums\AcademicHierarchyPreset;
use App\Models\AcademicUnit;
use App\Models\AcademicUnitType;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

test('academic hierarchy presets provision editable three-level paths for every institution type', function (AcademicHierarchyPreset $preset): void {
    $organization = Organization::factory()->create();
    $service = app(AcademicHierarchyPresetService::class);

    $units = $service->apply($organization, $preset);

    expect($units)->toHaveCount(3)
        ->and(AcademicUnitType::query()->where('organization_id', $organization->getKey())->count())->toBe(3)
        ->and(DB::table('academic_unit_type_edges')->where('organization_id', $organization->getKey())->count())->toBe(2)
        ->and(DB::table('academic_unit_closure')->where('organization_id', $organization->getKey())->count())->toBe(6)
        ->and($units->every(fn (AcademicUnit $unit): bool => $unit->organization_id === $organization->getKey()))->toBeTrue()
        ->and($units->first()->parent_id)->toBeNull();
})->with(AcademicHierarchyPreset::cases());

test('academic hierarchy presets are idempotent and preserve editable names', function (): void {
    $organization = Organization::factory()->create();
    $service = app(AcademicHierarchyPresetService::class);

    $service->apply($organization, AcademicHierarchyPreset::University);
    $type = AcademicUnitType::query()
        ->where('organization_id', $organization->getKey())
        ->where('code', 'university_college')
        ->firstOrFail();
    $type->update(['name' => 'My College Type']);

    $service->apply($organization, AcademicHierarchyPreset::University);

    expect(AcademicUnit::withTrashed()->where('organization_id', $organization->getKey())->count())->toBe(3)
        ->and(AcademicUnitType::query()->where('organization_id', $organization->getKey())->count())->toBe(3)
        ->and($type->fresh()->name)->toBe('My College Type');
});

test('academic hierarchy presets remain isolated between organizations', function (): void {
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $service = app(AcademicHierarchyPresetService::class);

    $service->apply($organization, AcademicHierarchyPreset::Preschool);
    $service->apply($foreignOrganization, AcademicHierarchyPreset::Preschool);

    expect(AcademicUnit::query()->where('organization_id', $organization->getKey())->count())->toBe(3)
        ->and(AcademicUnit::query()->where('organization_id', $foreignOrganization->getKey())->count())->toBe(3)
        ->and(DB::table('academic_unit_type_edges')->where('organization_id', $organization->getKey())->count())->toBe(2)
        ->and(DB::table('academic_unit_type_edges')->where('organization_id', $foreignOrganization->getKey())->count())->toBe(2);
});
