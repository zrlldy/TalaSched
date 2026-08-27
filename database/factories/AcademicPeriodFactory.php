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
            'organization_id' => fn (array $attributes): int => AcademicYear::query()
                ->whereKey((int) $attributes['academic_year_id'])
                ->firstOrFail()
                ->organization_id,
            'name' => 'Term 1',
            'kind' => AcademicPeriodKind::Term,
            'sequence' => 1,
            'starts_on' => fn (array $attributes) => AcademicYear::query()
                ->whereKey((int) $attributes['academic_year_id'])
                ->firstOrFail()
                ->starts_on,
            'ends_on' => fn (array $attributes) => AcademicYear::query()
                ->whereKey((int) $attributes['academic_year_id'])
                ->firstOrFail()
                ->starts_on
                ->copy()
                ->addMonths(4),
        ];
    }

    /**
     * Create the period within an existing academic year.
     */
    public function forAcademicYear(AcademicYear $academicYear): static
    {
        return $this->state(fn (array $attributes): array => [
            'academic_year_id' => $academicYear->getKey(),
            'organization_id' => $academicYear->organization_id,
            'starts_on' => $academicYear->starts_on,
            'ends_on' => $academicYear->ends_on,
        ]);
    }
}
