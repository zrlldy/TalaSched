<?php

namespace Database\Factories;

use App\Enums\DeliveryMode;
use App\Enums\SubjectComponentKind;
use App\Models\Subject;
use App\Models\SubjectComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubjectComponent> */
class SubjectComponentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'subject_id' => Subject::factory(),
            'organization_id' => fn (array $attributes): int => Subject::query()->findOrFail((int) $attributes['subject_id'])->organization_id,
            'kind' => SubjectComponentKind::Lecture,
            'name' => 'Lecture',
            'weekly_minutes' => 90,
            'sessions_per_week' => 1,
            'default_duration_minutes' => 90,
            'minimum_room_capacity' => null,
            'delivery_mode' => DeliveryMode::Physical,
        ];
    }

    public function forSubject(Subject $subject): static
    {
        return $this->state([
            'subject_id' => $subject->getKey(),
            'organization_id' => $subject->organization_id,
        ]);
    }

    public function laboratory(): static
    {
        return $this->state([
            'kind' => SubjectComponentKind::Laboratory,
            'name' => 'Laboratory',
            'weekly_minutes' => 180,
            'sessions_per_week' => 1,
            'default_duration_minutes' => 180,
        ]);
    }
}
