<?php

namespace App\Scheduling;

use App\Exceptions\ScheduleConflictException;
use App\Models\Organization;
use App\Models\ScheduleEntry;
use App\Models\TimetableVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateScheduleEntry
{
    public function __construct(private ValidateScheduleEntry $validator) {}

    public function handle(Organization $organization, ScheduleEntryData $data): ScheduleEntry
    {
        return DB::transaction(function () use ($organization, $data): ScheduleEntry {
            TimetableVersion::query()->whereKey($data->timetableVersionId)->lockForUpdate()->firstOrFail();

            $issues = $this->validator->handle($organization, $data);

            if ($issues !== []) {
                throw new ScheduleConflictException($issues);
            }

            $entry = ScheduleEntry::create([
                'organization_id' => $organization->id,
                'timetable_version_id' => $data->timetableVersionId,
                'offering_component_id' => $data->offeringComponentId,
                'logical_id' => (string) Str::uuid(),
                'weekday' => $data->weekday,
                'starts_at_minute' => $data->startsAtMinute,
                'ends_at_minute' => $data->endsAtMinute,
                'delivery_mode' => $data->deliveryMode,
                'notes' => $data->notes,
            ]);

            foreach ($data->resources as $assignment) {
                $entry->resources()->create([
                    'organization_id' => $organization->id,
                    'scheduling_resource_id' => $assignment['resource_id'],
                    'role' => $assignment['role'],
                ]);

                $entry->reservations()->create([
                    'organization_id' => $organization->id,
                    'timetable_version_id' => $data->timetableVersionId,
                    'scheduling_resource_id' => $assignment['resource_id'],
                    'weekday' => $data->weekday,
                    'starts_at_minute' => $data->startsAtMinute,
                    'ends_at_minute' => $data->endsAtMinute,
                    'is_active' => true,
                ]);
            }

            return $entry->load(['version', 'offeringComponent', 'resources.resource']);
        }, attempts: 3);
    }
}
