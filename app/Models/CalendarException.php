<?php

namespace App\Models;

use App\Concerns\HasImmutableOrganization;
use App\Enums\CalendarExceptionKind;
use Carbon\CarbonInterface;
use Database\Factories\CalendarExceptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property CarbonInterface $date
 * @property CalendarExceptionKind $kind
 */
#[Fillable(['organization_id', 'academic_period_id', 'date', 'kind', 'name', 'starts_at_minute', 'ends_at_minute'])]
class CalendarException extends Model
{
    /** @use HasFactory<CalendarExceptionFactory> */
    use HasFactory, HasImmutableOrganization;

    protected static function booted(): void
    {
        static::saving(function (CalendarException $calendarException): void {
            $period = AcademicPeriod::query()->find($calendarException->academic_period_id);

            if (! $period) {
                return;
            }

            if ($calendarException->organization_id !== $period->organization_id) {
                throw new LogicException('A calendar exception and its period must belong to the same organization.');
            }

            if ($calendarException->date->lt($period->starts_on) || $calendarException->date->gt($period->ends_on)) {
                throw new LogicException('A calendar exception must fall within its academic period.');
            }

            if (($calendarException->starts_at_minute === null) !== ($calendarException->ends_at_minute === null)) {
                throw new LogicException('Calendar exception windows require both start and end minutes.');
            }

            if ($calendarException->starts_at_minute !== null
                && ($calendarException->ends_at_minute > 1440
                    || $calendarException->starts_at_minute >= $calendarException->ends_at_minute)) {
                throw new LogicException('Calendar exception windows must be positive and fit within one day.');
            }

            if (in_array($calendarException->kind, [CalendarExceptionKind::Holiday, CalendarExceptionKind::Blocked], true)
                && $calendarException->starts_at_minute !== null) {
                throw new LogicException('Holiday and blocked calendar exceptions must cover the full day.');
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
            'kind' => CalendarExceptionKind::class,
            'date' => 'date',
            'starts_at_minute' => 'integer',
            'ends_at_minute' => 'integer',
        ];
    }
}
