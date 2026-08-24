<?php

namespace App\Academic;

use App\Enums\AcademicHierarchyPreset;
use App\Models\AcademicUnit;
use App\Models\AcademicUnitType;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class AcademicHierarchyPresetService
{
    public function __construct(
        private AcademicHierarchyService $hierarchyService,
        private TenantContext $tenantContext,
    ) {}

    /**
     * Provision an editable sample hierarchy for an organization.
     *
     * Existing records with the preset's stable codes are preserved so the
     * operation can safely be retried after an interrupted setup.
     *
     * @return Collection<int, AcademicUnit>
     */
    public function apply(Organization $organization, AcademicHierarchyPreset $preset): Collection
    {
        return $this->tenantContext->run($organization, function () use ($organization, $preset): Collection {
            return DB::transaction(function () use ($organization, $preset): Collection {
                $definition = $this->definition($preset);
                $types = $this->provisionTypes($organization, $definition['types']);

                $this->provisionEdges($organization, $types, $definition['types']);

                $units = [];

                foreach ($definition['units'] as $unitDefinition) {
                    $existingUnit = AcademicUnit::withTrashed()
                        ->where('organization_id', $organization->getKey())
                        ->where('code', $unitDefinition['code'])
                        ->first();

                    if ($existingUnit instanceof AcademicUnit) {
                        $units[$unitDefinition['code']] = $existingUnit;

                        continue;
                    }

                    $parent = null;

                    if ($unitDefinition['parent_code'] !== null) {
                        $parent = $units[$unitDefinition['parent_code']] ?? null;

                        if (! $parent instanceof AcademicUnit) {
                            throw new LogicException("Preset parent [{$unitDefinition['parent_code']}] was not provisioned.");
                        }
                    }

                    $type = $types[$unitDefinition['type_code']] ?? null;

                    if (! $type instanceof AcademicUnitType) {
                        throw new LogicException("Preset unit type [{$unitDefinition['type_code']}] was not provisioned.");
                    }

                    $units[$unitDefinition['code']] = $this->hierarchyService->create(
                        organization: $organization,
                        type: $type,
                        name: $unitDefinition['name'],
                        code: $unitDefinition['code'],
                        parent: $parent,
                    );
                }

                return (new Collection(array_values($units)))->sortBy('id')->values();
            }, attempts: 3);
        });
    }

    /**
     * @param  list<array{code: string, name: string, display_order: int}>  $typeDefinitions
     * @return array<string, AcademicUnitType>
     */
    private function provisionTypes(Organization $organization, array $typeDefinitions): array
    {
        $types = [];

        foreach ($typeDefinitions as $typeDefinition) {
            DB::table('academic_unit_types')->insertOrIgnore([
                'organization_id' => $organization->getKey(),
                'code' => $typeDefinition['code'],
                'name' => $typeDefinition['name'],
                'is_system' => false,
                'display_order' => $typeDefinition['display_order'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $type = AcademicUnitType::query()
                ->where('organization_id', $organization->getKey())
                ->where('code', $typeDefinition['code'])
                ->lockForUpdate()
                ->first();

            if (! $type instanceof AcademicUnitType) {
                throw new LogicException("Preset unit type [{$typeDefinition['code']}] could not be loaded.");
            }

            $types[$typeDefinition['code']] = $type;
        }

        return $types;
    }

    /**
     * @param  array<string, AcademicUnitType>  $types
     * @param  list<array{code: string, name: string, display_order: int}>  $typeDefinitions
     */
    private function provisionEdges(Organization $organization, array $types, array $typeDefinitions): void
    {
        foreach (array_keys($typeDefinitions) as $index => $unused) {
            if ($index === 0) {
                continue;
            }

            $parentType = $types[$typeDefinitions[$index - 1]['code']] ?? null;
            $childType = $types[$typeDefinitions[$index]['code']] ?? null;

            if (! $parentType instanceof AcademicUnitType || ! $childType instanceof AcademicUnitType) {
                throw new LogicException('Preset unit type edges require provisioned unit types.');
            }

            DB::table('academic_unit_type_edges')->insertOrIgnore([
                'organization_id' => $organization->getKey(),
                'parent_type_id' => $parentType->getKey(),
                'child_type_id' => $childType->getKey(),
            ]);
        }
    }

    /**
     * @return array{
     *     types: list<array{code: string, name: string, display_order: int}>,
     *     units: list<array{type_code: string, code: string, name: string, parent_code: string|null}>
     * }
     */
    private function definition(AcademicHierarchyPreset $preset): array
    {
        return match ($preset) {
            AcademicHierarchyPreset::Preschool => [
                'types' => [
                    ['code' => 'preschool_campus', 'name' => 'Campus', 'display_order' => 10],
                    ['code' => 'preschool_level', 'name' => 'Preschool level', 'display_order' => 20],
                    ['code' => 'preschool_class', 'name' => 'Class', 'display_order' => 30],
                ],
                'units' => [
                    ['type_code' => 'preschool_campus', 'code' => 'PRESCHOOL-CAMPUS', 'name' => 'Main Campus', 'parent_code' => null],
                    ['type_code' => 'preschool_level', 'code' => 'PRESCHOOL-LEVEL', 'name' => 'Preschool', 'parent_code' => 'PRESCHOOL-CAMPUS'],
                    ['type_code' => 'preschool_class', 'code' => 'PRESCHOOL-CLASS-A', 'name' => 'Class A', 'parent_code' => 'PRESCHOOL-LEVEL'],
                ],
            ],
            AcademicHierarchyPreset::KindergartenToGrade12 => [
                'types' => [
                    ['code' => 'k12_campus', 'name' => 'Campus', 'display_order' => 10],
                    ['code' => 'k12_grade_level', 'name' => 'Grade level', 'display_order' => 20],
                    ['code' => 'k12_section', 'name' => 'Section', 'display_order' => 30],
                ],
                'units' => [
                    ['type_code' => 'k12_campus', 'code' => 'K12-CAMPUS', 'name' => 'Main Campus', 'parent_code' => null],
                    ['type_code' => 'k12_grade_level', 'code' => 'K12-GRADE-1', 'name' => 'Grade 1', 'parent_code' => 'K12-CAMPUS'],
                    ['type_code' => 'k12_section', 'code' => 'K12-SECTION-A', 'name' => 'Section A', 'parent_code' => 'K12-GRADE-1'],
                ],
            ],
            AcademicHierarchyPreset::SeniorHigh => [
                'types' => [
                    ['code' => 'senior_high_campus', 'name' => 'Campus', 'display_order' => 10],
                    ['code' => 'senior_high_strand', 'name' => 'Academic strand', 'display_order' => 20],
                    ['code' => 'senior_high_section', 'name' => 'Section', 'display_order' => 30],
                ],
                'units' => [
                    ['type_code' => 'senior_high_campus', 'code' => 'SENIOR-HIGH-CAMPUS', 'name' => 'Main Campus', 'parent_code' => null],
                    ['type_code' => 'senior_high_strand', 'code' => 'SENIOR-HIGH-ACADEMIC', 'name' => 'Academic strand', 'parent_code' => 'SENIOR-HIGH-CAMPUS'],
                    ['type_code' => 'senior_high_section', 'code' => 'SENIOR-HIGH-SECTION-A', 'name' => 'Section A', 'parent_code' => 'SENIOR-HIGH-ACADEMIC'],
                ],
            ],
            AcademicHierarchyPreset::University => [
                'types' => [
                    ['code' => 'university_campus', 'name' => 'Campus', 'display_order' => 10],
                    ['code' => 'university_college', 'name' => 'College', 'display_order' => 20],
                    ['code' => 'university_program', 'name' => 'Program', 'display_order' => 30],
                ],
                'units' => [
                    ['type_code' => 'university_campus', 'code' => 'UNIVERSITY-CAMPUS', 'name' => 'Main Campus', 'parent_code' => null],
                    ['type_code' => 'university_college', 'code' => 'UNIVERSITY-COLLEGE-ARTS', 'name' => 'College of Arts', 'parent_code' => 'UNIVERSITY-CAMPUS'],
                    ['type_code' => 'university_program', 'code' => 'UNIVERSITY-PROGRAM-BA', 'name' => 'Bachelor of Arts', 'parent_code' => 'UNIVERSITY-COLLEGE-ARTS'],
                ],
            ],
            AcademicHierarchyPreset::TrainingCenter => [
                'types' => [
                    ['code' => 'training_center', 'name' => 'Training center', 'display_order' => 10],
                    ['code' => 'training_program', 'name' => 'Training program', 'display_order' => 20],
                    ['code' => 'training_cohort', 'name' => 'Cohort', 'display_order' => 30],
                ],
                'units' => [
                    ['type_code' => 'training_center', 'code' => 'TRAINING-CENTER', 'name' => 'Main Training Center', 'parent_code' => null],
                    ['type_code' => 'training_program', 'code' => 'TRAINING-PROGRAM-GENERAL', 'name' => 'General Program', 'parent_code' => 'TRAINING-CENTER'],
                    ['type_code' => 'training_cohort', 'code' => 'TRAINING-COHORT-1', 'name' => 'Cohort 1', 'parent_code' => 'TRAINING-PROGRAM-GENERAL'],
                ],
            ],
        };
    }
}
