<?php

namespace Database\Factories;

use App\Enums\ScheduleExceptionAction;
use App\Models\ScheduleEntry;
use App\Models\ScheduleEntryException;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleEntryException>
 */
class ScheduleEntryExceptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'schedule_entry_id' => ScheduleEntry::factory(),
            'organization_id' => fn (array $attributes): int => ScheduleEntry::query()
                ->findOrFail((int) $attributes['schedule_entry_id'])
                ->organization_id,
            'date' => '2026-08-24',
            'action' => ScheduleExceptionAction::Cancelled,
            'starts_at_minute' => null,
            'ends_at_minute' => null,
            'reason' => null,
        ];
    }
}
