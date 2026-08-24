<?php

namespace App\Models;

use App\Concerns\HasImmutableOrganization;
use App\Concerns\HasPublicId;
use App\Enums\DeliveryMode;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/** @property DeliveryMode $delivery_mode */
#[Fillable(['organization_id', 'scheduling_resource_id', 'building_id', 'room_type_id', 'code', 'name', 'capacity', 'delivery_mode'])]
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory, HasImmutableOrganization, HasPublicId, SoftDeletes;

    protected static function booted(): void
    {
        static::saving(function (Room $room): void {
            $relatedOrganizationIds = [
                SchedulingResource::query()->whereKey($room->scheduling_resource_id)->value('organization_id'),
                $room->building_id === null
                    ? null
                    : Building::query()->whereKey($room->building_id)->value('organization_id'),
                RoomType::query()->whereKey($room->room_type_id)->value('organization_id'),
            ];

            foreach ($relatedOrganizationIds as $relatedOrganizationId) {
                if ($relatedOrganizationId !== null && $relatedOrganizationId !== $room->organization_id) {
                    throw new LogicException('A room and its resource, building, and room type must belong to the same organization.');
                }
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<SchedulingResource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(SchedulingResource::class, 'scheduling_resource_id');
    }

    /** @return BelongsTo<Building, $this> */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /** @return BelongsToMany<Feature, $this> */
    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'room_features')
            ->withPivot(['organization_id', 'quantity']);
    }

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'delivery_mode' => DeliveryMode::class,
        ];
    }
}
