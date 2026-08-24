<?php

namespace Database\Factories;

use App\Models\AcademicCalendar;
use App\Models\AcademicPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicCalendar>
 */
class AcademicCalendarFactory extends Factory
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
            'organization_id' => function (array $attributes): int {
                return AcademicPeriod::query()->findOrFail((int) $attributes['academic_period_id'])->organization_id;
            },
            'weekday' => 1,
            'starts_at_minute' => 480,
            'ends_at_minute' => 960,
        ];
    }

    /**
     * Create the calendar inside an existing academic period.
     */
    public function forAcademicPeriod(AcademicPeriod $academicPeriod): static
    {
        return $this->state(fn (array $attributes): array => [
            'academic_period_id' => $academicPeriod->getKey(),
            'organization_id' => $academicPeriod->organization_id,
        ]);
    }
}
