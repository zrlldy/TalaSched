<?php

namespace App\Models;

use App\Concerns\HasImmutableOrganization;
use App\Concerns\HasPublicId;
use App\Enums\ScheduleExceptionAction;
use Carbon\CarbonInterface;
use Database\Factories\ScheduleEntryExceptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property CarbonInterface $date
 * @property ScheduleExceptionAction $action
 * @property int|null $starts_at_minute
 * @property int|null $ends_at_minute
 */
#[Fillable(['organization_id', 'schedule_entry_id', 'date', 'action', 'starts_at_minute', 'ends_at_minute', 'reason'])]
class ScheduleEntryException extends Model
{
    /** @use HasFactory<ScheduleEntryExceptionFactory> */
    use HasFactory, HasImmutableOrganization, HasPublicId;

    /** @return BelongsTo<ScheduleEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(ScheduleEntry::class, 'schedule_entry_id');
    }

    /** @return BelongsToMany<SchedulingResource, $this> */
    public function resources(): BelongsToMany
    {
        return $this->belongsToMany(
            SchedulingResource::class,
            'schedule_exception_resources',
            'schedule_entry_exception_id',
            'scheduling_resource_id',
        )->withPivot(['organization_id', 'role']);
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'action' => ScheduleExceptionAction::class,
            'starts_at_minute' => 'integer',
            'ends_at_minute' => 'integer',
        ];
    }
}
