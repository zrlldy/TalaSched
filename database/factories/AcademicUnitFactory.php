<?php

namespace Database\Factories;

use App\Models\AcademicUnit;
use App\Models\AcademicUnitType;
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
}
