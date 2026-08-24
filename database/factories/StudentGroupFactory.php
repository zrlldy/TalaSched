<?php

namespace Database\Factories;

use App\Enums\ResourceType;
use App\Models\AcademicUnit;
use App\Models\AcademicUnitType;
use App\Models\AcademicYear;
use App\Models\Organization;
use App\Models\SchedulingResource;
use App\Models\StudentGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentGroup>
 */
class StudentGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academic_year_id' => AcademicYear::factory(),
            'organization_id' => fn (array $attributes): int => AcademicYear::query()->findOrFail((int) $attributes['academic_year_id'])->organization_id,
            'scheduling_resource_id' => fn (array $attributes): int => SchedulingResource::factory()->create([
                'organization_id' => $attributes['organization_id'],
                'type' => ResourceType::StudentGroup,
                'name' => 'Group '.fake()->unique()->bothify('####'),
            ])->id,
            'academic_unit_id' => function (array $attributes): int {
                $type = AcademicUnitType::factory()->create(['organization_id' => $attributes['organization_id']]);

                return AcademicUnit::factory()->create([
                    'organization_id' => $attributes['organization_id'],
                    'academic_unit_type_id' => $type->id,
                ])->id;
            },
            'code' => fake()->unique()->bothify('GROUP-####'),
            'name' => 'Group '.fake()->bothify('####'),
            'active_from' => null,
            'active_until' => null,
            'expected_headcount' => fake()->numberBetween(10, 60),
        ];
    }

    /**
     * Create the group inside an existing academic year.
     */
    public function forAcademicYear(AcademicYear $academicYear): static
    {
        return $this->state(fn (array $attributes): array => [
            'academic_year_id' => $academicYear->getKey(),
            'organization_id' => $academicYear->organization_id,
        ]);
    }

    /**
     * Create the group inside an existing academic unit.
     */
    public function forAcademicUnit(AcademicUnit $academicUnit): static
    {
        return $this->state(fn (array $attributes): array => [
            'academic_unit_id' => $academicUnit->getKey(),
            'organization_id' => $academicUnit->organization_id,
        ]);
    }

    /**
     * Create the group inside an existing organization.
     */
    public function forOrganization(Organization $organization): static
    {
        return $this->state([
            'organization_id' => $organization->getKey(),
            'academic_year_id' => AcademicYear::factory()->forOrganization($organization),
            'scheduling_resource_id' => SchedulingResource::factory()->state([
                'organization_id' => $organization->getKey(),
                'type' => ResourceType::StudentGroup,
            ]),
            'academic_unit_id' => AcademicUnit::factory()->forOrganization($organization),
        ]);
    }
}
