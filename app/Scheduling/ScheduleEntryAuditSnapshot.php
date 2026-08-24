<?php

namespace App\Scheduling;

use App\Models\ScheduleEntry;
use App\Models\ScheduleEntryException;
use App\Models\ScheduleEntryResource;
use App\Models\SchedulingResource;
use Illuminate\Database\Eloquent\Relations\Pivot;
use LogicException;

final class ScheduleEntryAuditSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public function entry(ScheduleEntry $entry): array
    {
        $entry->loadMissing(['resources.resource']);

        return [
            'id' => $entry->public_id,
            'timetable_version_id' => $entry->timetable_version_id,
            'offering_component_id' => $entry->offering_component_id,
            'weekday' => (int) $entry->weekday,
            'starts_at_minute' => (int) $entry->starts_at_minute,
            'ends_at_minute' => (int) $entry->ends_at_minute,
            'delivery_mode' => (string) $entry->delivery_mode,
            'notes' => $entry->notes,
            'lock_version' => (int) $entry->lock_version,
            'resources' => $entry->resources
                ->map(fn (ScheduleEntryResource $assignment): array => [
                    'resource_id' => $assignment->resource->public_id,
                    'role' => $assignment->role->value,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function exception(ScheduleEntryException $exception): array
    {
        $exception->loadMissing(['entry', 'resources']);

        return [
            'id' => $exception->public_id,
            'schedule_entry_id' => $exception->entry->public_id,
            'date' => $exception->date->toDateString(),
            'action' => $exception->action->value,
            'starts_at_minute' => $exception->starts_at_minute,
            'ends_at_minute' => $exception->ends_at_minute,
            'reason' => $exception->reason,
            'resources' => $exception->resources
                ->map(function (SchedulingResource $resource): array {
                    $pivot = $resource->getRelationValue('pivot');

                    if (! $pivot instanceof Pivot) {
                        throw new LogicException('Schedule exception resources must include their pivot data.');
                    }

                    $role = $pivot->getAttribute('role');

                    if (! is_string($role)) {
                        throw new LogicException('Schedule exception resource pivots must include a role.');
                    }

                    return [
                        'resource_id' => $resource->public_id,
                        'role' => $role,
                    ];
                })
                ->values()
                ->all(),
        ];
    }
}
