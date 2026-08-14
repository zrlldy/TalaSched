<?php

namespace Database\Factories;

use App\Models\AcademicPeriod;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\SubjectOffering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubjectOffering>
 */
class SubjectOfferingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academic_period_id' => AcademicPeriod::factory(),
            'organization_id' => fn (array $attributes): int => AcademicPeriod::findOrFail($attributes['academic_period_id'])->organization_id,
            'subject_id' => fn (array $attributes): int => Subject::factory()->create([
                'organization_id' => $attributes['organization_id'],
            ])->id,
            'student_group_id' => function (array $attributes): int {
                $period = AcademicPeriod::findOrFail($attributes['academic_period_id']);

                return StudentGroup::factory()->create([
                    'organization_id' => $attributes['organization_id'],
                    'academic_year_id' => $period->academic_year_id,
                ])->id;
            },
            'owning_academic_unit_id' => fn (array $attributes): int => StudentGroup::findOrFail($attributes['student_group_id'])->academic_unit_id,
            'code' => fake()->unique()->bothify('OFFERING-####'),
            'expected_enrollment' => fake()->numberBetween(10, 60),
            'status' => 'active',
        ];
    }
}
