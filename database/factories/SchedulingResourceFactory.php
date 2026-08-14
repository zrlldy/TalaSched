<?php

namespace Database\Factories;

use App\Enums\ResourceType;
use App\Models\Organization;
use App\Models\SchedulingResource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchedulingResource>
 */
class SchedulingResourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'type' => ResourceType::Equipment,
            'name' => fake()->words(2, true),
            'is_active' => true,
        ];
    }

    public function faculty(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => ResourceType::Faculty,
        ]);
    }

    public function room(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => ResourceType::Room,
        ]);
    }

    public function studentGroup(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => ResourceType::StudentGroup,
        ]);
    }
}
