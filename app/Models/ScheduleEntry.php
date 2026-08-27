<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use Database\Factories\ScheduleEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $public_id
 * @property int $weekday
 * @property int $starts_at_minute
 * @property int $ends_at_minute
 * @property string $delivery_mode
 * @property string|null $notes
 * @property int $lock_version
 * @property-read TimetableVersion $version
 * @property-read OfferingComponent $offeringComponent
 * @property-read Collection<int, ScheduleEntryResource> $resources
 */
#[Fillable(['organization_id', 'timetable_version_id', 'offering_component_id', 'logical_id', 'weekday', 'starts_at_minute', 'ends_at_minute', 'delivery_mode', 'notes', 'lock_version'])]
class ScheduleEntry extends Model
{
    /** @use HasFactory<ScheduleEntryFactory> */
    use HasFactory, HasPublicId;

    /** @return BelongsTo<TimetableVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(TimetableVersion::class, 'timetable_version_id');
    }

    /** @return BelongsTo<OfferingComponent, $this> */
    public function offeringComponent(): BelongsTo
    {
        return $this->belongsTo(OfferingComponent::class);
    }

    /** @return HasMany<ScheduleEntryResource, $this> */
    public function resources(): HasMany
    {
        return $this->hasMany(ScheduleEntryResource::class);
    }

    /** @return HasMany<ScheduleReservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(ScheduleReservation::class);
    }

    /** @return HasMany<ScheduleEntryException, $this> */
    public function exceptions(): HasMany
    {
        return $this->hasMany(ScheduleEntryException::class);
    }
}
