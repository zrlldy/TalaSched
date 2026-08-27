<?php

namespace Database\Factories;

use App\Enums\TimetableVersionStatus;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimetableVersion>
 */
class TimetableVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'timetable_id' => Timetable::factory(),
            'organization_id' => fn (array $attributes): int => Timetable::query()
                ->whereKey((int) $attributes['timetable_id'])
                ->firstOrFail()
                ->organization_id,
            'based_on_version_id' => null,
            'version_number' => 1,
            'status' => TimetableVersionStatus::Draft,
            'lock_version' => 1,
            'created_by' => null,
            'published_by' => null,
            'submitted_at' => null,
            'approved_at' => null,
            'published_at' => null,
        ];
    }
}
