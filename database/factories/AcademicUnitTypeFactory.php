<?php

namespace Database\Factories;

use App\Models\AcademicUnitType;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicUnitType>
 */
class AcademicUnitTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'code' => fake()->unique()->bothify('unit-type-####'),
            'name' => fake()->randomElement(['Campus', 'Department', 'Program', 'Grade Level', 'Year Level']),
            'is_system' => false,
            'display_order' => fake()->numberBetween(0, 20),
        ];
    }
}
