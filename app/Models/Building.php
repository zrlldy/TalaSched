<?php

namespace App\Models;

use App\Concerns\HasImmutableOrganization;
use Database\Factories\BuildingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['organization_id', 'campus_academic_unit_id', 'code', 'name'])]
class Building extends Model
{
    /** @use HasFactory<BuildingFactory> */
    use HasFactory, HasImmutableOrganization;

    protected static function booted(): void
    {
        static::saving(function (Building $building): void {
            if ($building->campus_academic_unit_id === null) {
                return;
            }

            $campusOrganizationId = AcademicUnit::query()
                ->whereKey($building->campus_academic_unit_id)
                ->value('organization_id');

            if ($campusOrganizationId !== null && $campusOrganizationId !== $building->organization_id) {
                throw new LogicException('A building and its campus must belong to the same organization.');
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<AcademicUnit, $this> */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(AcademicUnit::class, 'campus_academic_unit_id');
    }

    /** @return HasMany<Room, $this> */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }
}
