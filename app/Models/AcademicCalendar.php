<?php

namespace App\Models;

use App\Concerns\HasImmutableOrganization;
use Database\Factories\AcademicCalendarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['organization_id', 'academic_period_id', 'weekday', 'starts_at_minute', 'ends_at_minute'])]
class AcademicCalendar extends Model
{
    /** @use HasFactory<AcademicCalendarFactory> */
    use HasFactory, HasImmutableOrganization;

    protected static function booted(): void
    {
        static::saving(function (AcademicCalendar $academicCalendar): void {
            $period = AcademicPeriod::query()->find($academicCalendar->academic_period_id);

            if ($period && $academicCalendar->organization_id !== $period->organization_id) {
                throw new LogicException('An academic calendar and its period must belong to the same organization.');
            }

            if ($academicCalendar->weekday < 1 || $academicCalendar->weekday > 7) {
                throw new LogicException('Academic calendar weekdays must be between one and seven.');
            }

            if ($academicCalendar->ends_at_minute > 1440
                || $academicCalendar->starts_at_minute >= $academicCalendar->ends_at_minute) {
                throw new LogicException('Academic calendar windows must be positive and fit within one day.');
            }
        });
    }

    /**
     * Get the organization-owned academic period.
     *
     * @return BelongsTo<AcademicPeriod, $this>
     */
    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class);
    }

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'starts_at_minute' => 'integer',
            'ends_at_minute' => 'integer',
        ];
    }
}
