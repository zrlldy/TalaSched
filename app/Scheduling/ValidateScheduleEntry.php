<?php

namespace App\Scheduling;

use App\Enums\AvailabilityKind;
use App\Enums\ResourceType;
use App\Enums\ScheduleResourceRole;
use App\Models\FacultyProfile;
use App\Models\OfferingComponent;
use App\Models\Organization;
use App\Models\ResourceAvailabilityRule;
use App\Models\Room;
use App\Models\ScheduleReservation;
use App\Models\SchedulingResource;
use App\Models\TimetableVersion;

class ValidateScheduleEntry
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function handle(Organization $organization, ScheduleEntryData $data): array
    {
        $version = TimetableVersion::query()
            ->with('timetable')
            ->where('organization_id', $organization->id)
            ->findOrFail($data->timetableVersionId);

        $component = OfferingComponent::query()
            ->with('offering.studentGroup.resource')
            ->where('organization_id', $organization->id)
            ->findOrFail($data->offeringComponentId);

        $resources = SchedulingResource::query()
            ->where('organization_id', $organization->id)
            ->whereIn('id', collect($data->resources)->pluck('resource_id'))
            ->get()
            ->keyBy('id');

        $issues = [];

        if (! $version->status->isEditable()) {
            $issues[] = $this->issue('version_not_editable', 'hard', 'timetable_version_id', 'Published or review-locked timetable versions cannot be edited.');
        }

        if ($data->startsAtMinute % $version->timetable->scheduling_granularity !== 0
            || $data->endsAtMinute % $version->timetable->scheduling_granularity !== 0) {
            $issues[] = $this->issue('invalid_granularity', 'hard', 'starts_at_minute', 'Start and end times must follow the timetable scheduling interval.');
        }

        $expectedResourceTypes = [
            ScheduleResourceRole::Instructor->value => ResourceType::Faculty,
            ScheduleResourceRole::StudentGroup->value => ResourceType::StudentGroup,
            ScheduleResourceRole::Room->value => ResourceType::Room,
            ScheduleResourceRole::Equipment->value => ResourceType::Equipment,
        ];

        foreach ($data->resources as $assignment) {
            $resource = $resources->get($assignment['resource_id']);
            $expectedType = $expectedResourceTypes[$assignment['role']] ?? null;

            if ($resource === null || $expectedType !== $resource->type) {
                $issues[] = $this->resourceIssue('resource_role_mismatch', $resource?->public_id, $resource?->name, 'The selected resource does not match its scheduling role.');
            }
        }

        $groupResourceId = $component->offering->studentGroup->scheduling_resource_id;
        $assignedGroupIds = collect($data->resources)
            ->where('role', ScheduleResourceRole::StudentGroup->value)
            ->pluck('resource_id');

        if (! $assignedGroupIds->contains($groupResourceId)) {
            $issues[] = $this->resourceIssue('offering_group_mismatch', $component->offering->studentGroup->resource->public_id, $component->offering->studentGroup->name, 'The offering must be scheduled for its assigned student group.');
        }

        $overlaps = ScheduleReservation::query()
            ->with('scheduleEntry:id,public_id')
            ->where('organization_id', $organization->id)
            ->where('timetable_version_id', $version->id)
            ->whereIn('scheduling_resource_id', $resources->keys())
            ->where('weekday', $data->weekday)
            ->where('starts_at_minute', '<', $data->endsAtMinute)
            ->where('ends_at_minute', '>', $data->startsAtMinute)
            ->where('is_active', true)
            ->get();

        foreach ($overlaps as $overlap) {
            $resource = $resources->get($overlap->scheduling_resource_id);
            $issues[] = $this->resourceIssue('resource_overlap', $resource?->public_id, $resource?->name, 'The resource is already assigned during this time.', $overlap->scheduleEntry->public_id);
        }

        $unavailable = ResourceAvailabilityRule::query()
            ->where('organization_id', $organization->id)
            ->whereIn('scheduling_resource_id', $resources->keys())
            ->where(fn ($query) => $query->whereNull('academic_period_id')->orWhere('academic_period_id', $version->timetable->academic_period_id))
            ->where('kind', AvailabilityKind::Unavailable)
            ->where('weekday', $data->weekday)
            ->where('starts_at_minute', '<', $data->endsAtMinute)
            ->where('ends_at_minute', '>', $data->startsAtMinute)
            ->get();

        foreach ($unavailable as $rule) {
            $resource = $resources->get($rule->scheduling_resource_id);
            $issues[] = $this->resourceIssue('resource_unavailable', $resource?->public_id, $resource?->name, 'The resource is unavailable during this time.');
        }

        $roomResourceIds = $resources->where('type', ResourceType::Room)->keys();
        $rooms = Room::query()->where('organization_id', $organization->id)->whereIn('scheduling_resource_id', $roomResourceIds)->get();

        foreach ($rooms as $room) {
            if ($room->capacity !== null && $room->capacity < $component->offering->expected_enrollment) {
                $issues[] = $this->resourceIssue('room_capacity', $resources->get($room->scheduling_resource_id)?->public_id, $room->name, 'The room capacity is below the offering enrollment.');
            }

            if ($component->required_room_type_id !== null && $component->required_room_type_id !== $room->room_type_id) {
                $issues[] = $this->resourceIssue('room_type_required', $resources->get($room->scheduling_resource_id)?->public_id, $room->name, 'The room does not satisfy the required room type.');
            }
        }

        $this->appendFacultyLoadIssues($issues, $organization, $version, $resources, $data);

        return $issues;
    }

    /** @param array<int, array<string, mixed>> $issues */
    private function appendFacultyLoadIssues(array &$issues, Organization $organization, TimetableVersion $version, $resources, ScheduleEntryData $data): void
    {
        $duration = $data->endsAtMinute - $data->startsAtMinute;
        $facultyResourceIds = $resources->where('type', ResourceType::Faculty)->keys();
        $facultyProfiles = FacultyProfile::query()->where('organization_id', $organization->id)->whereIn('scheduling_resource_id', $facultyResourceIds)->get();

        foreach ($facultyProfiles as $faculty) {
            $dailyLoad = ScheduleReservation::query()
                ->where('timetable_version_id', $version->id)
                ->where('scheduling_resource_id', $faculty->scheduling_resource_id)
                ->where('weekday', $data->weekday)
                ->get()
                ->sum(fn (ScheduleReservation $reservation): int => $reservation->ends_at_minute - $reservation->starts_at_minute);

            $weeklyLoad = ScheduleReservation::query()
                ->where('timetable_version_id', $version->id)
                ->where('scheduling_resource_id', $faculty->scheduling_resource_id)
                ->get()
                ->sum(fn (ScheduleReservation $reservation): int => $reservation->ends_at_minute - $reservation->starts_at_minute);

            if ($faculty->maximum_daily_minutes !== null && $dailyLoad + $duration > $faculty->maximum_daily_minutes) {
                $issues[] = $this->resourceIssue('faculty_daily_load', $resources->get($faculty->scheduling_resource_id)?->public_id, $resources->get($faculty->scheduling_resource_id)?->name, 'The faculty member would exceed the maximum daily teaching load.');
            }

            if ($faculty->maximum_weekly_minutes !== null && $weeklyLoad + $duration > $faculty->maximum_weekly_minutes) {
                $issues[] = $this->resourceIssue('faculty_weekly_load', $resources->get($faculty->scheduling_resource_id)?->public_id, $resources->get($faculty->scheduling_resource_id)?->name, 'The faculty member would exceed the maximum weekly teaching load.');
            }
        }
    }

    /** @return array<string, mixed> */
    private function issue(string $code, string $severity, string $field, string $message): array
    {
        return compact('code', 'severity', 'field', 'message') + ['rule_code' => $code, 'details' => []];
    }

    /** @return array<string, mixed> */
    private function resourceIssue(string $code, ?string $resourceId, ?string $name, string $message, ?string $conflictingEntryId = null): array
    {
        return $this->issue($code, 'hard', 'resources', $message) + [
            'resource' => ['id' => $resourceId, 'name' => $name],
            'conflicting_entry_id' => $conflictingEntryId,
        ];
    }
}
