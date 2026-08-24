<?php

namespace App\Http\Resources\Scheduling;

use App\Models\ScheduleEntryException;
use App\Models\SchedulingResource;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/** @mixin ScheduleEntryException */
class ScheduleEntryExceptionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'type' => 'schedule_entry_exception',
            'attributes' => [
                'schedule_entry_id' => $this->entry->public_id,
                'date' => $this->date->toDateString(),
                'action' => $this->action->value,
                'starts_at_minute' => $this->starts_at_minute,
                'ends_at_minute' => $this->ends_at_minute,
                'reason' => $this->reason,
                'resources' => $this->resources->map(function (SchedulingResource $resource): array {
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
                })->values()->all(),
            ],
        ];
    }
}
