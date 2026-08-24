<?php

namespace App\Models;

use App\Concerns\HasImmutableOrganization;
use Database\Factories\FeatureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['organization_id', 'code', 'name'])]
class Feature extends Model
{
    /** @use HasFactory<FeatureFactory> */
    use HasFactory, HasImmutableOrganization;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsToMany<Room, $this> */
    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class, 'room_features')
            ->withPivot(['organization_id', 'quantity']);
    }

    /** @return BelongsToMany<SubjectComponent, $this> */
    public function subjectComponents(): BelongsToMany
    {
        return $this->belongsToMany(SubjectComponent::class, 'subject_component_features')
            ->withPivot(['organization_id', 'minimum_quantity']);
    }

    /** @return BelongsToMany<OfferingComponent, $this> */
    public function offeringComponents(): BelongsToMany
    {
        return $this->belongsToMany(OfferingComponent::class, 'offering_component_features')
            ->withPivot(['organization_id', 'minimum_quantity']);
    }
}
