<?php

namespace App\Models;

use App\Concerns\HasImmutableOrganization;
use App\Concerns\HasPublicId;
use Database\Factories\SubjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['organization_id', 'code', 'name', 'description', 'units', 'is_active'])]
class Subject extends Model
{
    /** @use HasFactory<SubjectFactory> */
    use HasFactory, HasImmutableOrganization, HasPublicId, SoftDeletes;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<SubjectComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(SubjectComponent::class);
    }

    /** @return HasMany<SubjectOffering, $this> */
    public function offerings(): HasMany
    {
        return $this->hasMany(SubjectOffering::class);
    }

    protected function casts(): array
    {
        return ['units' => 'decimal:2', 'is_active' => 'boolean'];
    }
}
