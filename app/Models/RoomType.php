<?php

namespace App\Models;

use App\Concerns\HasImmutableOrganization;
use Database\Factories\RoomTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'code', 'name', 'is_system'])]
class RoomType extends Model
{
    /** @use HasFactory<RoomTypeFactory> */
    use HasFactory, HasImmutableOrganization;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<Room, $this> */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /** @return HasMany<OfferingComponent, $this> */
    public function offeringComponents(): HasMany
    {
        return $this->hasMany(OfferingComponent::class, 'required_room_type_id');
    }

    /** @return BelongsToMany<SubjectComponent, $this> */
    public function subjectComponents(): BelongsToMany
    {
        return $this->belongsToMany(SubjectComponent::class, 'subject_component_room_types')
            ->withPivot('organization_id');
    }

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }
}
