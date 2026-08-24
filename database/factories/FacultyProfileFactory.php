<?php

namespace Database\Factories;

use App\Enums\FacultyEmploymentType;
use App\Enums\ResourceType;
use App\Models\FacultyProfile;
use App\Models\Organization;
use App\Models\SchedulingResource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FacultyProfile>
 */
class FacultyProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scheduling_resource_id' => SchedulingResource::factory()->state([
                'type' => ResourceType::Faculty,
                'name' => fake()->name(),
            ]),
            'organization_id' => fn (array $attributes): int => SchedulingResource::query()
                ->findOrFail((int) $attributes['scheduling_resource_id'])
                ->organization_id,
            'user_id' => null,
            'employee_number' => fake()->unique()->bothify('FAC-####'),
            'position' => fake()->randomElement(['Teacher', 'Instructor', 'Professor']),
            'employment_type' => fake()->randomElement(FacultyEmploymentType::cases()),
            'maximum_daily_minutes' => 360,
            'maximum_weekly_minutes' => 1200,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state([
            'organization_id' => $organization->getKey(),
            'scheduling_resource_id' => SchedulingResource::factory()->forOrganization($organization)->faculty(),
        ]);
    }

    public function forResource(SchedulingResource $resource): static
    {
        return $this->state([
            'scheduling_resource_id' => $resource->getKey(),
            'organization_id' => $resource->organization_id,
        ]);
    }
}
