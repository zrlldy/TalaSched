<?php

namespace Database\Factories;

use App\Enums\ResourceType;
use App\Models\Building;
use App\Models\Organization;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\SchedulingResource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
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
                'type' => ResourceType::Room,
                'name' => 'Room '.fake()->unique()->bothify('###'),
            ]),
            'organization_id' => fn (array $attributes): int => SchedulingResource::query()
                ->findOrFail((int) $attributes['scheduling_resource_id'])
                ->organization_id,
            'building_id' => null,
            'room_type_id' => fn (array $attributes): int => RoomType::factory()->create([
                'organization_id' => $attributes['organization_id'],
            ])->getKey(),
            'code' => fake()->unique()->bothify('ROOM-###'),
            'name' => 'Room '.fake()->bothify('###'),
            'capacity' => fake()->numberBetween(20, 80),
            'delivery_mode' => 'physical',
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state([
            'organization_id' => $organization->getKey(),
            'scheduling_resource_id' => SchedulingResource::factory()->forOrganization($organization)->room(),
            'room_type_id' => RoomType::factory()->forOrganization($organization),
        ]);
    }

    public function inBuilding(Building $building): static
    {
        return $this->state([
            'organization_id' => $building->organization_id,
            'building_id' => $building->getKey(),
        ]);
    }

    public function ofType(RoomType $roomType): static
    {
        return $this->state([
            'organization_id' => $roomType->organization_id,
            'room_type_id' => $roomType->getKey(),
        ]);
    }
}
