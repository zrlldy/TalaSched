<?php

namespace App\Scheduling;

use App\Enums\ResourceType;
use App\Models\FacultyProfile;
use App\Models\OfferingComponent;
use App\Models\Organization;
use App\Models\Room;
use App\Models\ScheduleEntry;
use App\Models\ScheduleReservation;
use App\Models\SchedulingResource;
use App\Models\TimetableVersion;

class SchedulingContextFactory
{
    public function make(
        Organization $organization,
        ScheduleEntryData $data,
        bool $checkVersionEditability = true,
    ): SchedulingContext {
        $version = TimetableVersion::query()
            ->with([
                'timetable.academicPeriod.calendars',
                'timetable.academicPeriod.calendarExceptions',
            ])
            ->where('organization_id', $organization->getKey())
            ->findOrFail($data->timetableVersionId);
        $component = OfferingComponent::query()
            ->with([
                'offering.studentGroup.resource',
                'features',
                'instructors',
            ])
            ->where('organization_id', $organization->getKey())
            ->findOrFail($data->offeringComponentId);
        $resourceIds = collect($data->resources)->pluck('resource_id');
        $resources = SchedulingResource::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('id', $resourceIds)
            ->get()
            ->keyBy('id');
        $reservations = ScheduleReservation::query()
            ->with('scheduleEntry:id,public_id')
            ->where('organization_id', $organization->getKey())
            ->where('timetable_version_id', $version->getKey())
            ->whereIn('scheduling_resource_id', $resources->keys())
            ->where('is_active', true)
            ->get();
        if ($data->existingEntryId !== null) {
            $reservations = $reservations->where('schedule_entry_id', '!=', $data->existingEntryId);
        }
        $rooms = Room::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('scheduling_resource_id', $resources->where('type', ResourceType::Room)->keys())
            ->with(['roomType', 'features'])
            ->get()
            ->keyBy('scheduling_resource_id');
        $facultyProfiles = FacultyProfile::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('scheduling_resource_id', $resources->where('type', ResourceType::Faculty)->keys())
            ->get()
            ->keyBy('scheduling_resource_id');
        $existingEntries = ScheduleEntry::query()
            ->where('organization_id', $organization->getKey())
            ->where('timetable_version_id', $version->getKey())
            ->where('offering_component_id', $component->getKey())
            ->get();
        if ($data->existingEntryId !== null) {
            $existingEntries = $existingEntries->where('id', '!=', $data->existingEntryId);
        }
        $academicPeriod = $version->timetable->academicPeriod;

        return new SchedulingContext(
            organization: $organization,
            version: $version,
            offeringComponent: $component,
            academicPeriod: $academicPeriod,
            resources: $resources,
            reservations: $reservations,
            rooms: $rooms,
            facultyProfiles: $facultyProfiles,
            calendars: $academicPeriod->calendars->keyBy('weekday'),
            calendarExceptions: $academicPeriod->calendarExceptions,
            existingEntries: $existingEntries,
            data: $data,
            checkVersionEditability: $checkVersionEditability,
        );
    }
}
