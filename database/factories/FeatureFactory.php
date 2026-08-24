<?php

namespace Database\Factories;

use App\Models\Feature;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Feature> */
class FeatureFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'code' => fake()->unique()->bothify('FEATURE-####'),
            'name' => fake()->randomElement(['Projector', 'Computer lab', 'Accessible seating']),
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(['organization_id' => $organization->getKey()]);
    }
}
