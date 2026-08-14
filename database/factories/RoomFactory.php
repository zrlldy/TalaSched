<?php

namespace Database\Factories;

use App\Enums\ResourceType;
use App\Models\Room;
use App\Models\SchedulingResource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

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
            'organization_id' => fn (array $attributes): int => SchedulingResource::findOrFail($attributes['scheduling_resource_id'])->organization_id,
            'building_id' => null,
            'room_type_id' => fn (array $attributes): int => DB::table('room_types')->insertGetId([
                'organization_id' => $attributes['organization_id'],
                'code' => fake()->unique()->bothify('room-type-####'),
                'name' => 'Classroom',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'code' => fake()->unique()->bothify('ROOM-###'),
            'name' => 'Room '.fake()->bothify('###'),
            'capacity' => fake()->numberBetween(20, 80),
            'delivery_mode' => 'physical',
        ];
    }
}
