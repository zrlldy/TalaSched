<?php

namespace App\Models;

use App\Concerns\HasImmutableOrganization;
use App\Concerns\HasPublicId;
use App\Enums\ResourceType;
use Database\Factories\SchedulingResourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $public_id
 * @property ResourceType $type
 * @property string $name
 */
#[Fillable(['organization_id', 'type', 'name', 'is_active'])]
class SchedulingResource extends Model
{
    /** @use HasFactory<SchedulingResourceFactory> */
    use HasFactory, HasImmutableOrganization, HasPublicId;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<ResourceAvailabilityRule, $this> */
    public function availabilityRules(): HasMany
    {
        return $this->hasMany(ResourceAvailabilityRule::class);
    }

    /** @return HasOne<FacultyProfile, $this> */
    public function facultyProfile(): HasOne
    {
        return $this->hasOne(FacultyProfile::class);
    }

    /** @return HasOne<Room, $this> */
    public function room(): HasOne
    {
        return $this->hasOne(Room::class);
    }

    /** @return HasOne<StudentGroup, $this> */
    public function studentGroup(): HasOne
    {
        return $this->hasOne(StudentGroup::class);
    }

    protected function casts(): array
    {
        return ['type' => ResourceType::class, 'is_active' => 'boolean'];
    }
}
