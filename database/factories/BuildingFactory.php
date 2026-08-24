<?php

namespace Database\Factories;

use App\Models\AcademicUnit;
use App\Models\Building;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Building> */
class BuildingFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'campus_academic_unit_id' => null,
            'code' => fake()->unique()->bothify('BLDG-####'),
            'name' => fake()->randomElement(['Main Building', 'Science Building', 'Arts Building']),
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(['organization_id' => $organization->getKey()]);
    }

    public function onCampus(AcademicUnit $campus): static
    {
        return $this->state([
            'organization_id' => $campus->organization_id,
            'campus_academic_unit_id' => $campus->getKey(),
        ]);
    }
}
