<?php

namespace Database\Factories;

use App\Enums\ResourceType;
use App\Models\AcademicUnit;
use App\Models\AcademicUnitType;
use App\Models\AcademicYear;
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
            'organization_id' => fn (array $attributes): int => AcademicYear::findOrFail($attributes['academic_year_id'])->organization_id,
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
            'expected_headcount' => fake()->numberBetween(10, 60),
        ];
    }
}
