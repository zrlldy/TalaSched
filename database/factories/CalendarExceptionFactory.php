<?php

namespace Database\Factories;

use App\Enums\CalendarExceptionKind;
use App\Models\AcademicPeriod;
use App\Models\CalendarException;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarException>
 */
class CalendarExceptionFactory extends Factory
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
            'date' => function (array $attributes) {
                return AcademicPeriod::query()->findOrFail((int) $attributes['academic_period_id'])->starts_on;
            },
            'kind' => CalendarExceptionKind::Holiday,
            'name' => 'Public holiday',
            'starts_at_minute' => null,
            'ends_at_minute' => null,
        ];
    }

    /**
     * Create the exception inside an existing academic period.
     */
    public function forAcademicPeriod(AcademicPeriod $academicPeriod): static
    {
        return $this->state(fn (array $attributes): array => [
            'academic_period_id' => $academicPeriod->getKey(),
            'organization_id' => $academicPeriod->organization_id,
            'date' => $academicPeriod->starts_on,
        ]);
    }
}
