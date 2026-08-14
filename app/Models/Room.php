<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['organization_id', 'scheduling_resource_id', 'building_id', 'room_type_id', 'code', 'name', 'capacity', 'delivery_mode'])]
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory, HasPublicId, SoftDeletes;

    public function resource(): BelongsTo
    {
        return $this->belongsTo(SchedulingResource::class, 'scheduling_resource_id');
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'room_features')->withPivot('quantity');
    }
}
