<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use Database\Factories\ScheduleEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'timetable_version_id', 'offering_component_id', 'logical_id', 'weekday', 'starts_at_minute', 'ends_at_minute', 'delivery_mode', 'notes', 'lock_version'])]
class ScheduleEntry extends Model
{
    /** @use HasFactory<ScheduleEntryFactory> */
    use HasFactory, HasPublicId;

    public function version(): BelongsTo
    {
        return $this->belongsTo(TimetableVersion::class, 'timetable_version_id');
    }

    public function offeringComponent(): BelongsTo
    {
        return $this->belongsTo(OfferingComponent::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(ScheduleEntryResource::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(ScheduleReservation::class);
    }
}
