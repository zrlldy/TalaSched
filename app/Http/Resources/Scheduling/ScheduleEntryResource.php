<?php

namespace App\Http\Resources\Scheduling;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'type' => 'schedule_entry',
            'attributes' => [
                'timetable_version_id' => $this->version->public_id,
                'offering_component_id' => $this->offeringComponent->public_id,
                'weekday' => $this->weekday,
                'starts_at_minute' => $this->starts_at_minute,
                'ends_at_minute' => $this->ends_at_minute,
                'delivery_mode' => $this->delivery_mode,
                'notes' => $this->notes,
                'lock_version' => $this->lock_version,
                'resources' => $this->resources->map(fn ($assignment): array => [
                    'resource_id' => $assignment->resource->public_id,
                    'role' => $assignment->role->value,
                ])->values()->all(),
            ],
        ];
    }
}
