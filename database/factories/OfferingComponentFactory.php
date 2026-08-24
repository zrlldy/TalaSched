<?php

namespace Database\Factories;

use App\Enums\DeliveryMode;
use App\Enums\SubjectComponentKind;
use App\Models\OfferingComponent;
use App\Models\SubjectComponent;
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
            'organization_id' => fn (array $attributes): int => SubjectOffering::query()
                ->findOrFail((int) $attributes['subject_offering_id'])
                ->organization_id,
            'subject_component_id' => null,
            'kind' => SubjectComponentKind::Lecture,
            'name' => 'Lecture',
            'weekly_minutes' => 90,
            'sessions_per_week' => 1,
            'duration_minutes' => 90,
            'minimum_room_capacity' => null,
            'required_room_type_id' => null,
            'delivery_mode' => DeliveryMode::Physical,
        ];
    }

    public function forOffering(SubjectOffering $offering): static
    {
        return $this->state([
            'subject_offering_id' => $offering->getKey(),
            'organization_id' => $offering->organization_id,
        ]);
    }

    public function forSubjectComponent(SubjectComponent $component): static
    {
        return $this->state([
            'subject_component_id' => $component->getKey(),
            'organization_id' => $component->organization_id,
            'kind' => $component->kind,
            'name' => $component->name,
            'weekly_minutes' => $component->weekly_minutes,
            'sessions_per_week' => $component->sessions_per_week,
            'duration_minutes' => $component->default_duration_minutes,
            'delivery_mode' => $component->delivery_mode,
        ]);
    }
}
