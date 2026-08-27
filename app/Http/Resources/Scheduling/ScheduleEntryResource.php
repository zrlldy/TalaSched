<?php

namespace App\Http\Resources\Scheduling;

use App\Models\ScheduleEntry;
use App\Models\ScheduleEntryResource as ScheduleEntryResourceModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

class ScheduleEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $entry = $this->resource;

        if (! $entry instanceof ScheduleEntry) {
            throw new LogicException('ScheduleEntryResource requires a ScheduleEntry model.');
        }

        return [
            'id' => $entry->public_id,
            'type' => 'schedule_entry',
            'attributes' => [
                'timetable_version_id' => $entry->version->public_id,
                'offering_component_id' => $entry->offeringComponent->public_id,
                'weekday' => $entry->weekday,
                'starts_at_minute' => $entry->starts_at_minute,
                'ends_at_minute' => $entry->ends_at_minute,
                'delivery_mode' => $entry->delivery_mode,
                'notes' => $entry->notes,
                'lock_version' => $entry->lock_version,
                'resources' => $entry->resources->map(fn (ScheduleEntryResourceModel $assignment): array => [
                    'resource_id' => $assignment->resource->public_id,
                    'role' => $assignment->role->value,
                ])->values()->all(),
            ],
        ];
    }
}
