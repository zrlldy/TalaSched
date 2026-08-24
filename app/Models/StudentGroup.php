<?php

namespace App\Models;

use App\Concerns\HasImmutableOrganization;
use App\Concerns\HasPublicId;
use Carbon\CarbonInterface;
use Database\Factories\StudentGroupFactory;
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
#[Fillable(['organization_id', 'scheduling_resource_id', 'academic_year_id', 'academic_unit_id', 'code', 'name', 'active_from', 'active_until', 'expected_headcount'])]
class StudentGroup extends Model
{
    /** @use HasFactory<StudentGroupFactory> */
    use HasFactory, HasImmutableOrganization, HasPublicId, SoftDeletes;

    protected static function booted(): void
    {
        static::saving(function (StudentGroup $studentGroup): void {
            $relatedOrganizationIds = [
                AcademicYear::query()->whereKey($studentGroup->academic_year_id)->value('organization_id'),
                AcademicUnit::query()->whereKey($studentGroup->academic_unit_id)->value('organization_id'),
                SchedulingResource::query()->whereKey($studentGroup->scheduling_resource_id)->value('organization_id'),
            ];

            foreach ($relatedOrganizationIds as $relatedOrganizationId) {
                if ($relatedOrganizationId !== null && $studentGroup->organization_id !== $relatedOrganizationId) {
                    throw new LogicException('A student group and its academic, unit, and resource records must belong to the same organization.');
                }
            }

            $academicYear = AcademicYear::query()->find($studentGroup->academic_year_id);

            if (! $academicYear) {
                return;
            }

            if ($studentGroup->active_from && $studentGroup->active_until
                && $studentGroup->active_from->greaterThan($studentGroup->active_until)) {
                throw new LogicException('A student group must end on or after its active start date.');
            }

            if (($studentGroup->active_from && $studentGroup->active_from->lt($academicYear->starts_on))
                || ($studentGroup->active_until && $studentGroup->active_until->gt($academicYear->ends_on))) {
                throw new LogicException('A student group active range must fit within its academic year.');
            }
        });
    }

    /**
     * Get the organization that owns the student group.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the paired scheduling resource.
     *
     * @return BelongsTo<SchedulingResource, $this>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(SchedulingResource::class, 'scheduling_resource_id');
    }

    /**
     * Get the academic year for the group.
     *
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Get the academic unit containing the group.
     *
     * @return BelongsTo<AcademicUnit, $this>
     */
    public function academicUnit(): BelongsTo
    {
        return $this->belongsTo(AcademicUnit::class);
    }

    /**
     * Get the academic periods in which the group participates.
     *
     * @return BelongsToMany<AcademicPeriod, $this>
     */
    public function periods(): BelongsToMany
    {
        return $this->belongsToMany(AcademicPeriod::class, 'student_group_periods', 'student_group_id', 'academic_period_id')
            ->withPivot('organization_id');
    }

    /**
     * Get offerings assigned to the group.
     *
     * @return HasMany<SubjectOffering, $this>
     */
    public function subjectOfferings(): HasMany
    {
        return $this->hasMany(SubjectOffering::class);
    }

    protected function casts(): array
    {
        return [
            'active_from' => 'date',
            'active_until' => 'date',
            'expected_headcount' => 'integer',
        ];
    }
}
