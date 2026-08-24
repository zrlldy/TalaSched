<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RoomType> */
class RoomTypeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'code' => fake()->unique()->bothify('ROOM-TYPE-####'),
            'name' => fake()->randomElement(['Classroom', 'Laboratory', 'Auditorium']),
            'is_system' => false,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(['organization_id' => $organization->getKey()]);
    }
}
