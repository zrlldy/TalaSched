<?php

namespace App\Models;

use App\Concerns\HasImmutableOrganization;
use App\Concerns\HasPublicId;
use App\Enums\AcademicYearStatus;
use Carbon\CarbonInterface;
use Database\Factories\AcademicYearFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property AcademicYearStatus $status
 * @property CarbonInterface $starts_on
 * @property CarbonInterface $ends_on
 */
#[Fillable(['organization_id', 'name', 'starts_on', 'ends_on', 'status'])]
class AcademicYear extends Model
{
    /** @use HasFactory<AcademicYearFactory> */
    use HasFactory, HasImmutableOrganization, HasPublicId;

    protected static function booted(): void
    {
        static::saving(function (AcademicYear $academicYear): void {
            if ($academicYear->starts_on->greaterThan($academicYear->ends_on)) {
                throw new LogicException('An academic year must end on or after its start date.');
            }
        });
    }

    /**
     * Get the organization that owns the academic year.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the periods in the academic year.
     *
     * @return HasMany<AcademicPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(AcademicPeriod::class);
    }

    /**
     * Get the student groups in the academic year.
     *
     * @return HasMany<StudentGroup, $this>
     */
    public function studentGroups(): HasMany
    {
        return $this->hasMany(StudentGroup::class);
    }

    /**
     * Get the timetables in the academic year.
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
            'status' => AcademicYearStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }
}
