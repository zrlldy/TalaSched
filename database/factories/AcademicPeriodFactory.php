<?php

namespace Database\Factories;

use App\Enums\AcademicPeriodKind;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicPeriod>
 */
class AcademicPeriodFactory extends Factory
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
            'name' => 'Term 1',
            'kind' => AcademicPeriodKind::Term,
            'sequence' => 1,
            'starts_on' => fn (array $attributes) => AcademicYear::findOrFail($attributes['academic_year_id'])->starts_on,
            'ends_on' => fn (array $attributes) => AcademicYear::findOrFail($attributes['academic_year_id'])->starts_on->copy()->addMonths(4),
        ];
    }
}
