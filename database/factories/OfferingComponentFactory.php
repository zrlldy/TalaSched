<?php

namespace Database\Factories;

use App\Models\OfferingComponent;
use App\Models\SubjectOffering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfferingComponent>
 */
class OfferingComponentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_offering_id' => SubjectOffering::factory(),
            'organization_id' => fn (array $attributes): int => SubjectOffering::findOrFail($attributes['subject_offering_id'])->organization_id,
            'subject_component_id' => null,
            'kind' => 'lecture',
            'name' => 'Lecture',
            'weekly_minutes' => 90,
            'sessions_per_week' => 1,
            'duration_minutes' => 90,
            'minimum_room_capacity' => null,
            'required_room_type_id' => null,
            'delivery_mode' => 'physical',
        ];
    }
}
