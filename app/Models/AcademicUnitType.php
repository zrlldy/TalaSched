<?php

namespace App\Models;

use App\Concerns\HasImmutableOrganization;
use Database\Factories\AcademicUnitTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'code', 'name', 'is_system', 'display_order'])]
class AcademicUnitType extends Model
{
    /** @use HasFactory<AcademicUnitTypeFactory> */
    use HasFactory, HasImmutableOrganization;

    /**
     * Get the organization that owns the unit type.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the units using this type.
     *
     * @return HasMany<AcademicUnit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(AcademicUnit::class);
    }

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'display_order' => 'integer',
        ];
    }
}
