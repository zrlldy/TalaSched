<?php

namespace Database\Factories;

use App\Models\AcademicPeriod;
use App\Models\Timetable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Timetable>
 */
class TimetableFactory extends Factory
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
            'academic_year_id' => fn (array $attributes): int => AcademicPeriod::findOrFail($attributes['academic_period_id'])->academic_year_id,
            'name' => fake()->unique()->words(3, true).' timetable',
            'timezone' => 'Asia/Manila',
            'scheduling_granularity' => 30,
        ];
    }
}
