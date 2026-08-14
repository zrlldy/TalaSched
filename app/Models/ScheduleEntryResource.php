<?php

namespace App\Models;

use App\Enums\ScheduleResourceRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'schedule_entry_id', 'scheduling_resource_id', 'role'])]
class ScheduleEntryResource extends Model
{
    public function resource(): BelongsTo
    {
        return $this->belongsTo(SchedulingResource::class, 'scheduling_resource_id');
    }

    protected function casts(): array
    {
        return ['role' => ScheduleResourceRole::class];
    }
}
