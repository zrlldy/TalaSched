<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\GenerationRun;
use App\Models\Organization;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GenerationRun>
 */
class GenerationRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_timetable_version_id' => TimetableVersion::factory(),
            'public_id' => (string) Str::uuid(),
            'organization_id' => fn (array $attributes): int => TimetableVersion::query()
                ->whereKey((int) $attributes['source_timetable_version_id'])
                ->firstOrFail()
                ->organization_id,
            'timetable_id' => fn (array $attributes): int => TimetableVersion::query()
                ->whereKey((int) $attributes['source_timetable_version_id'])
                ->firstOrFail()
                ->timetable_id,
            'output_timetable_version_id' => null,
            'requested_by' => null,
            'input_schema_version' => 1,
            'seed' => fake()->numberBetween(1, 2_147_483_647),
            'input_snapshot' => fn (array $attributes): array => $this->snapshotFor(
                TimetableVersion::query()->findOrFail((int) $attributes['source_timetable_version_id']),
                (int) $attributes['seed'],
            ),
            'status' => GenerationStatus::Pending,
            'progress_percent' => null,
            'progress_message' => null,
            'diagnostics' => null,
            'cancel_requested_at' => null,
            'started_at' => null,
            'completed_at' => null,
            'failed_at' => null,
        ];
    }

    /**
     * Create a run for a known source version without crossing a tenant boundary.
     */
    public function forSourceVersion(TimetableVersion $sourceVersion): static
    {
        return $this->state(function (array $attributes) use ($sourceVersion): array {
            return [
                'organization_id' => $sourceVersion->organization_id,
                'timetable_id' => $sourceVersion->timetable_id,
                'source_timetable_version_id' => $sourceVersion->getKey(),
                'input_snapshot' => $this->snapshotFor($sourceVersion, (int) $attributes['seed']),
            ];
        });
    }

    /** @return array<string, mixed> */
    private function snapshotFor(TimetableVersion $sourceVersion, int $seed): array
    {
        $timetable = Timetable::query()->findOrFail($sourceVersion->timetable_id);
        $organization = Organization::query()->findOrFail($sourceVersion->organization_id);

        return [
            'schema_version' => 1,
            'organization_id' => $organization->public_id,
            'timetable_id' => $timetable->public_id,
            'source_version_id' => $sourceVersion->public_id,
            'timezone' => $timetable->timezone,
            'granularity_minutes' => $timetable->scheduling_granularity,
            'requirements' => [],
            'fixed_entries' => [],
            'constraints' => [],
            'seed' => $seed,
        ];
    }
}
