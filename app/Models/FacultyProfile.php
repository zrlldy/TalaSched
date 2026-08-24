<?php

namespace App\Models;

use App\Concerns\HasImmutableOrganization;
use App\Concerns\HasPublicId;
use App\Enums\FacultyEmploymentType;
use Database\Factories\FacultyProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * @property FacultyEmploymentType|null $employment_type
 * @property int|null $maximum_daily_minutes
 * @property int|null $maximum_weekly_minutes
 */
#[Fillable(['organization_id', 'scheduling_resource_id', 'user_id', 'employee_number', 'position', 'employment_type', 'maximum_daily_minutes', 'maximum_weekly_minutes'])]
class FacultyProfile extends Model
{
    /** @use HasFactory<FacultyProfileFactory> */
    use HasFactory, HasImmutableOrganization, HasPublicId, SoftDeletes;

    protected static function booted(): void
    {
        static::saving(function (FacultyProfile $profile): void {
            $resourceOrganizationId = SchedulingResource::query()
                ->whereKey($profile->scheduling_resource_id)
                ->value('organization_id');

            if ($resourceOrganizationId !== null && $resourceOrganizationId !== $profile->organization_id) {
                throw new LogicException('A faculty profile and its resource must belong to the same organization.');
            }

            if (($profile->maximum_daily_minutes !== null && $profile->maximum_daily_minutes < 1)
                || ($profile->maximum_weekly_minutes !== null && $profile->maximum_weekly_minutes < 1)) {
                throw new LogicException('Faculty load limits must be positive when provided.');
            }

            if ($profile->maximum_daily_minutes !== null
                && $profile->maximum_weekly_minutes !== null
                && $profile->maximum_daily_minutes > $profile->maximum_weekly_minutes) {
                throw new LogicException('A faculty daily load limit cannot exceed the weekly load limit.');
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<AcademicUnit, $this> */
    public function academicUnits(): BelongsToMany
    {
        return $this->belongsToMany(AcademicUnit::class, 'faculty_unit_assignments')
            ->withPivot(['organization_id', 'is_primary']);
    }

    /** @return BelongsToMany<OfferingComponent, $this> */
    public function offeringComponents(): BelongsToMany
    {
        return $this->belongsToMany(OfferingComponent::class, 'offering_instructors')
            ->withPivot(['organization_id', 'load_percentage', 'is_primary']);
    }

    protected function casts(): array
    {
        return [
            'employment_type' => FacultyEmploymentType::class,
            'maximum_daily_minutes' => 'integer',
            'maximum_weekly_minutes' => 'integer',
        ];
    }
}
