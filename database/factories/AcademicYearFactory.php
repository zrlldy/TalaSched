<?php

namespace Database\Factories;

use App\Enums\AcademicYearStatus;
use App\Models\AcademicYear;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicYear>
 */
class AcademicYearFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsOn = fake()->dateTimeBetween('-2 years', '+2 years')->setTime(0, 0);
        $endsOn = (clone $startsOn)->modify('+1 year -1 day');

        return [
            'organization_id' => Organization::factory(),
            'name' => $startsOn->format('Y').'-'.$endsOn->format('Y'),
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'status' => AcademicYearStatus::Draft,
        ];
    }

    /**
     * Create the academic year inside an existing organization.
     */
    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (array $attributes): array => [
            'organization_id' => $organization->getKey(),
        ]);
    }
}
