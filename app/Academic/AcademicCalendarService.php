<?php

namespace App\Academic;

use App\Enums\AcademicYearStatus;
use App\Enums\CalendarExceptionKind;
use App\Models\AcademicCalendar;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\CalendarException;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcademicCalendarService
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * Create or replace operating hours for one weekday in an academic period.
     */
    public function setOperatingHours(
        Organization $organization,
        AcademicPeriod $academicPeriod,
        int $weekday,
        int $startsAtMinute,
        int $endsAtMinute,
    ): AcademicCalendar {
        return $this->tenantContext->run($organization, function () use ($organization, $academicPeriod, $weekday, $startsAtMinute, $endsAtMinute): AcademicCalendar {
            return DB::transaction(function () use ($organization, $academicPeriod, $weekday, $startsAtMinute, $endsAtMinute): AcademicCalendar {
                $this->lockOrganization($organization);
                $lockedPeriod = $this->lockPeriod($organization, $academicPeriod);
                $this->assertPeriodIsOpen($lockedPeriod);
                $this->assertWeekday($weekday);
                $this->assertTimeWindow($startsAtMinute, $endsAtMinute);

                return AcademicCalendar::query()->updateOrCreate(
                    [
                        'organization_id' => $organization->getKey(),
                        'academic_period_id' => $lockedPeriod->getKey(),
                        'weekday' => $weekday,
                    ],
                    [
                        'starts_at_minute' => $startsAtMinute,
                        'ends_at_minute' => $endsAtMinute,
                    ],
                )->fresh();
            }, attempts: 3);
        });
    }

    /**
     * Create or replace the exception for one date in an academic period.
     */
    public function setException(
        Organization $organization,
        AcademicPeriod $academicPeriod,
        CarbonInterface $date,
        CalendarExceptionKind $kind,
        string $name,
        ?int $startsAtMinute = null,
        ?int $endsAtMinute = null,
    ): CalendarException {
        return $this->tenantContext->run($organization, function () use ($organization, $academicPeriod, $date, $kind, $name, $startsAtMinute, $endsAtMinute): CalendarException {
            return DB::transaction(function () use ($organization, $academicPeriod, $date, $kind, $name, $startsAtMinute, $endsAtMinute): CalendarException {
                $this->lockOrganization($organization);
                $lockedPeriod = $this->lockPeriod($organization, $academicPeriod);
                $this->assertPeriodIsOpen($lockedPeriod);
                $this->assertDateWithinPeriod($lockedPeriod, $date);
                $this->assertExceptionWindow($kind, $startsAtMinute, $endsAtMinute);

                $exception = CalendarException::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('academic_period_id', $lockedPeriod->getKey())
                    ->whereDate('date', $date->toDateString())
                    ->first();

                if (! $exception instanceof CalendarException) {
                    $exception = new CalendarException([
                        'organization_id' => $organization->getKey(),
                        'academic_period_id' => $lockedPeriod->getKey(),
                        'date' => $date,
                    ]);
                }

                $exception->fill([
                    'kind' => $kind,
                    'name' => $name,
                    'starts_at_minute' => $startsAtMinute,
                    'ends_at_minute' => $endsAtMinute,
                ]);
                $exception->save();

                return $exception->fresh();
            }, attempts: 3);
        });
    }

    private function lockOrganization(Organization $organization): Organization
    {
        return Organization::query()
            ->whereKey($organization->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockPeriod(Organization $organization, AcademicPeriod $academicPeriod): AcademicPeriod
    {
        return AcademicPeriod::query()
            ->whereKey($academicPeriod->getKey())
            ->where('organization_id', $organization->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertPeriodIsOpen(AcademicPeriod $academicPeriod): void
    {
        $academicYear = AcademicYear::query()
            ->whereKey($academicPeriod->academic_year_id)
            ->where('organization_id', $academicPeriod->organization_id)
            ->firstOrFail();

        if ($academicYear->status === AcademicYearStatus::Closed) {
            throw ValidationException::withMessages([
                'academic_period' => __('Closed academic years cannot have calendar changes.'),
            ]);
        }
    }

    private function assertDateWithinPeriod(AcademicPeriod $academicPeriod, CarbonInterface $date): void
    {
        if ($date->lt($academicPeriod->starts_on) || $date->gt($academicPeriod->ends_on)) {
            throw ValidationException::withMessages([
                'date' => __('Calendar exceptions must fall within the academic period.'),
            ]);
        }
    }

    private function assertWeekday(int $weekday): void
    {
        if ($weekday < 1 || $weekday > 7) {
            throw ValidationException::withMessages([
                'weekday' => __('Calendar weekdays must be between one and seven.'),
            ]);
        }
    }

    private function assertTimeWindow(int $startsAtMinute, int $endsAtMinute): void
    {
        if ($startsAtMinute < 0 || $endsAtMinute < 0 || $endsAtMinute > 1440 || $startsAtMinute >= $endsAtMinute) {
            throw ValidationException::withMessages([
                'time' => __('Operating hours must be a positive window within one day.'),
            ]);
        }
    }

    private function assertExceptionWindow(
        CalendarExceptionKind $kind,
        ?int $startsAtMinute,
        ?int $endsAtMinute,
    ): void {
        if (($startsAtMinute === null) !== ($endsAtMinute === null)) {
            throw ValidationException::withMessages([
                'time' => __('Calendar exception windows require both start and end minutes.'),
            ]);
        }

        if ($startsAtMinute !== null && ($startsAtMinute < 0 || $endsAtMinute < 0 || $endsAtMinute > 1440 || $startsAtMinute >= $endsAtMinute)) {
            throw ValidationException::withMessages([
                'time' => __('Calendar exception windows must be a positive window within one day.'),
            ]);
        }

        if (in_array($kind, [CalendarExceptionKind::Holiday, CalendarExceptionKind::Blocked], true)
            && $startsAtMinute !== null) {
            throw ValidationException::withMessages([
                'time' => __('Holiday and blocked exceptions must cover the full day.'),
            ]);
        }
    }
}
