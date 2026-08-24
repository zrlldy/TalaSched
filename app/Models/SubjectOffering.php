<?php

namespace App\Models;

use App\Concerns\HasImmutableOrganization;
use App\Concerns\HasPublicId;
use App\Enums\SubjectOfferingStatus;
use Database\Factories\SubjectOfferingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** @property SubjectOfferingStatus $status */
#[Fillable(['organization_id', 'academic_period_id', 'subject_id', 'student_group_id', 'owning_academic_unit_id', 'code', 'expected_enrollment', 'status'])]
class SubjectOffering extends Model
{
    /** @use HasFactory<SubjectOfferingFactory> */
    use HasFactory, HasImmutableOrganization, HasPublicId;

    protected static function booted(): void
    {
        static::saving(function (SubjectOffering $offering): void {
            $periodYearId = AcademicPeriod::query()
                ->whereKey($offering->academic_period_id)
                ->value('academic_year_id');
            $groupYearId = StudentGroup::query()
                ->whereKey($offering->student_group_id)
                ->value('academic_year_id');

            if ($periodYearId !== null && $groupYearId !== null && $periodYearId !== $groupYearId) {
                throw new LogicException('An offering period and student group must belong to the same academic year.');
            }
        });

        static::saving(function (SubjectOffering $offering): void {
            $relatedOrganizationIds = [
                AcademicPeriod::query()->whereKey($offering->academic_period_id)->value('organization_id'),
                Subject::query()->whereKey($offering->subject_id)->value('organization_id'),
                StudentGroup::query()->whereKey($offering->student_group_id)->value('organization_id'),
                $offering->owning_academic_unit_id === null
                    ? null
                    : AcademicUnit::query()->whereKey($offering->owning_academic_unit_id)->value('organization_id'),
            ];

            foreach ($relatedOrganizationIds as $relatedOrganizationId) {
                if ($relatedOrganizationId !== null && $relatedOrganizationId !== $offering->organization_id) {
                    throw new LogicException('An offering and its period, subject, group, and owning unit must belong to the same organization.');
                }
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<AcademicPeriod, $this> */
    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class);
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** @return BelongsTo<StudentGroup, $this> */
    public function studentGroup(): BelongsTo
    {
        return $this->belongsTo(StudentGroup::class);
    }

    /** @return BelongsTo<AcademicUnit, $this> */
    public function owningAcademicUnit(): BelongsTo
    {
        return $this->belongsTo(AcademicUnit::class, 'owning_academic_unit_id');
    }

    /** @return HasMany<OfferingComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(OfferingComponent::class);
    }

    protected function casts(): array
    {
        return [
            'expected_enrollment' => 'integer',
            'status' => SubjectOfferingStatus::class,
        ];
    }
}
