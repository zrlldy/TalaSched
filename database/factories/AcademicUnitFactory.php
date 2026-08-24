<?php

namespace Database\Factories;

use App\Models\AcademicUnit;
use App\Models\AcademicUnitType;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicUnit>
 */
class AcademicUnitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academic_unit_type_id' => AcademicUnitType::factory(),
            'organization_id' => fn (array $attributes): int => AcademicUnitType::findOrFail($attributes['academic_unit_type_id'])->organization_id,
            'parent_id' => null,
            'code' => fake()->unique()->bothify('UNIT-####'),
            'name' => fake()->company(),
            'active_from' => null,
            'active_until' => null,
        ];
    }

    /**
     * Create the unit with an existing unit type and its organization.
     */
    public function forType(AcademicUnitType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'academic_unit_type_id' => $type->getKey(),
            'organization_id' => $type->organization_id,
        ]);
    }

    /**
     * Create the unit and its generated type inside an existing organization.
     */
    public function forOrganization(Organization $organization): static
    {
        return $this->state([
            'organization_id' => $organization->getKey(),
            'academic_unit_type_id' => AcademicUnitType::factory()->forOrganization($organization),
        ]);
    }

    /**
     * Create the unit under an existing parent unit.
     */
    public function under(AcademicUnit $parent): static
    {
        return $this->state(fn (array $attributes): array => [
            'organization_id' => $parent->organization_id,
            'parent_id' => $parent->getKey(),
        ]);
    }
}
