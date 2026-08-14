<?php

namespace App\Models;

use App\Enums\AvailabilityKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'scheduling_resource_id', 'academic_period_id', 'kind', 'weekday', 'starts_at_minute', 'ends_at_minute', 'effective_from', 'effective_until', 'priority'])]
class ResourceAvailabilityRule extends Model
{
    public function resource(): BelongsTo
    {
        return $this->belongsTo(SchedulingResource::class, 'scheduling_resource_id');
    }

    protected function casts(): array
    {
        return ['kind' => AvailabilityKind::class, 'effective_from' => 'date', 'effective_until' => 'date'];
    }
}
