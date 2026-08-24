<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $starts_at_minute
 * @property int $ends_at_minute
 */
#[Fillable(['organization_id', 'timetable_version_id', 'schedule_entry_id', 'scheduling_resource_id', 'weekday', 'starts_at_minute', 'ends_at_minute', 'is_active'])]
class ScheduleReservation extends Model
{
    /** @return BelongsTo<ScheduleEntry, $this> */
    public function scheduleEntry(): BelongsTo
    {
        return $this->belongsTo(ScheduleEntry::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
