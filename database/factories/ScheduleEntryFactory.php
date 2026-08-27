<?php

namespace Database\Factories;

use App\Enums\ScheduleResourceRole;
use App\Models\FacultyProfile;
use App\Models\OfferingComponent;
use App\Models\Room;
use App\Models\ScheduleEntry;
use App\Models\SchedulingResource;
use App\Models\SubjectOffering;
use App\Models\TimetableVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ScheduleEntry>
 */
class ScheduleEntryFactory extends Factory
{
    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (ScheduleEntry $entry): void {
            $entry->loadMissing('offeringComponent.offering.studentGroup');

            $facultyResource = SchedulingResource::factory()->faculty()->create([
                'organization_id' => $entry->organization_id,
            ]);
            $faculty = FacultyProfile::factory()->create([
                'organization_id' => $entry->organization_id,
                'scheduling_resource_id' => $facultyResource->id,
            ]);
            $roomResource = SchedulingResource::factory()->room()->create([
                'organization_id' => $entry->organization_id,
            ]);
            $room = Room::factory()->create([
                'organization_id' => $entry->organization_id,
                'scheduling_resource_id' => $roomResource->id,
            ]);

            $assignments = [
                [$faculty->scheduling_resource_id, ScheduleResourceRole::Instructor],
                [$entry->offeringComponent->offering->studentGroup->scheduling_resource_id, ScheduleResourceRole::StudentGroup],
                [$room->scheduling_resource_id, ScheduleResourceRole::Room],
            ];

            foreach ($assignments as [$resourceId, $role]) {
                $entry->resources()->create([
                    'organization_id' => $entry->organization_id,
                    'scheduling_resource_id' => $resourceId,
                    'role' => $role,
                ]);
                $entry->reservations()->create([
                    'organization_id' => $entry->organization_id,
                    'timetable_version_id' => $entry->timetable_version_id,
                    'scheduling_resource_id' => $resourceId,
                    'weekday' => $entry->weekday,
                    'starts_at_minute' => $entry->starts_at_minute,
                    'ends_at_minute' => $entry->ends_at_minute,
                    'is_active' => true,
                ]);
            }
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'timetable_version_id' => TimetableVersion::factory(),
            'organization_id' => fn (array $attributes): int => TimetableVersion::query()
                ->whereKey((int) $attributes['timetable_version_id'])
                ->firstOrFail()
                ->organization_id,
            'offering_component_id' => function (array $attributes): int {
                $version = TimetableVersion::query()
                    ->with('timetable')
                    ->whereKey((int) $attributes['timetable_version_id'])
                    ->firstOrFail();
                $offering = SubjectOffering::factory()->create([
                    'organization_id' => $attributes['organization_id'],
                    'academic_period_id' => $version->timetable->academic_period_id,
                ]);

                return OfferingComponent::factory()->create([
                    'organization_id' => $attributes['organization_id'],
                    'subject_offering_id' => $offering->id,
                ])->id;
            },
            'logical_id' => (string) Str::uuid(),
            'weekday' => fake()->numberBetween(1, 5),
            'starts_at_minute' => 480,
            'ends_at_minute' => 570,
            'delivery_mode' => 'physical',
            'notes' => null,
            'lock_version' => 1,
        ];
    }
}
