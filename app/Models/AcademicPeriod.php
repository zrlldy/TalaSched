<?php

namespace App\Models;

use App\Concerns\HasImmutableOrganization;
use App\Concerns\HasPublicId;
use App\Enums\AcademicPeriodKind;
use Carbon\CarbonInterface;
use Database\Factories\AcademicPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property AcademicPeriodKind $kind
 * @property int $sequence
 * @property CarbonInterface $starts_on
 * @property CarbonInterface $ends_on
 */
#[Fillable(['organization_id', 'academic_year_id', 'name', 'kind', 'sequence', 'starts_on', 'ends_on'])]
class AcademicPeriod extends Model
{
    /** @use HasFactory<AcademicPeriodFactory> */
    use HasFactory, HasImmutableOrganization, HasPublicId;

    protected static function booted(): void
    {
        static::saving(function (AcademicPeriod $academicPeriod): void {
            $academicYear = AcademicYear::query()->find($academicPeriod->academic_year_id);

            if (! $academicYear) {
                return;
            }

            if ($academicPeriod->organization_id !== $academicYear->organization_id) {
                throw new LogicException('An academic period and its academic year must belong to the same organization.');
            }

            if ($academicPeriod->starts_on->lt($academicYear->starts_on)
                || $academicPeriod->ends_on->gt($academicYear->ends_on)) {
                throw new LogicException('An academic period must fit within its academic year.');
            }

            if ($academicPeriod->starts_on->greaterThan($academicPeriod->ends_on)) {
                throw new LogicException('An academic period must end on or after its start date.');
            }

            if ($academicPeriod->sequence < 1) {
                throw new LogicException('Academic period sequences must start at one.');
            }
        });
    }

    /**
     * Get the organization that owns the academic period.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the academic year containing this period.
     *
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Get the student groups participating in the period.
     *
     * @return BelongsToMany<StudentGroup, $this>
     */
    public function studentGroups(): BelongsToMany
    {
        return $this->belongsToMany(StudentGroup::class, 'student_group_periods', 'academic_period_id', 'student_group_id')
            ->withPivot('organization_id');
    }

    /**
     * Get the resource availability rules scoped to the period.
     *
     * @return HasMany<ResourceAvailabilityRule, $this>
     */
    public function availabilityRules(): HasMany
    {
        return $this->hasMany(ResourceAvailabilityRule::class);
    }

    /**
     * Get the operating hours for this period.
     *
     * @return HasMany<AcademicCalendar, $this>
     */
    public function calendars(): HasMany
    {
        return $this->hasMany(AcademicCalendar::class);
    }

    /**
     * Get the date-specific calendar exceptions for this period.
     *
     * @return HasMany<CalendarException, $this>
     */
    public function calendarExceptions(): HasMany
    {
        return $this->hasMany(CalendarException::class);
    }

    /**
     * Get the subject offerings in the period.
     *
     * @return HasMany<SubjectOffering, $this>
     */
    public function subjectOfferings(): HasMany
    {
        return $this->hasMany(SubjectOffering::class);
    }

    /**
     * Get the timetables in the period.
     *
     * @return HasMany<Timetable, $this>
     */
    public function timetables(): HasMany
    {
        return $this->hasMany(Timetable::class);
    }

    protected function casts(): array
    {
        return [
            'kind' => AcademicPeriodKind::class,
            'sequence' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }
}
