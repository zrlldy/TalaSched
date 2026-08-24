<?php

namespace App\Models;

use App\Concerns\HasImmutableOrganization;
use App\Concerns\HasPublicId;
use Carbon\CarbonInterface;
use Database\Factories\AcademicUnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * @property CarbonInterface|null $active_from
 * @property CarbonInterface|null $active_until
 */
#[Fillable(['organization_id', 'academic_unit_type_id', 'parent_id', 'code', 'name', 'active_from', 'active_until'])]
class AcademicUnit extends Model
{
    /** @use HasFactory<AcademicUnitFactory> */
    use HasFactory, HasImmutableOrganization, HasPublicId, SoftDeletes;

    protected static function booted(): void
    {
        static::saving(function (AcademicUnit $academicUnit): void {
            $unitType = AcademicUnitType::query()->find($academicUnit->academic_unit_type_id);

            if ($unitType && $academicUnit->organization_id !== $unitType->organization_id) {
                throw new LogicException('An academic unit and its type must belong to the same organization.');
            }

            if ($academicUnit->parent_id !== null && $academicUnit->parent_id === $academicUnit->getKey()) {
                throw new LogicException('An academic unit cannot be its own parent.');
            }

            $parent = $academicUnit->parent_id
                ? self::query()->find($academicUnit->parent_id)
                : null;

            if ($parent && $academicUnit->organization_id !== $parent->organization_id) {
                throw new LogicException('An academic unit and its parent must belong to the same organization.');
            }

            if ($academicUnit->active_from && $academicUnit->active_until
                && $academicUnit->active_from->greaterThan($academicUnit->active_until)) {
                throw new LogicException('An academic unit must end on or after its active start date.');
            }
        });
    }

    /**
     * Get the organization that owns the unit.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the configured unit type.
     *
     * @return BelongsTo<AcademicUnitType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(AcademicUnitType::class, 'academic_unit_type_id');
    }

    /**
     * Get the parent unit.
     *
     * @return BelongsTo<AcademicUnit, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Get the direct child units.
     *
     * @return HasMany<AcademicUnit, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Get student groups assigned to the unit.
     *
     * @return HasMany<StudentGroup, $this>
     */
    public function studentGroups(): HasMany
    {
        return $this->hasMany(StudentGroup::class);
    }

    /**
     * Get closure ancestors, including this unit at depth zero.
     *
     * @return BelongsToMany<AcademicUnit, $this>
     */
    public function ancestors(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'academic_unit_closure', 'descendant_id', 'ancestor_id')
            ->withPivot(['organization_id', 'depth']);
    }

    /**
     * Get closure descendants, including this unit at depth zero.
     *
     * @return BelongsToMany<AcademicUnit, $this>
     */
    public function descendants(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'academic_unit_closure', 'ancestor_id', 'descendant_id')
            ->withPivot(['organization_id', 'depth']);
    }

    protected function casts(): array
    {
        return ['active_from' => 'date', 'active_until' => 'date'];
    }
}
