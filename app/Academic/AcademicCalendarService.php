<?php

namespace App\Academic;

use App\Audit\AuditLogger;
use App\Enums\AcademicYearStatus;
use App\Enums\CalendarExceptionKind;
use App\Models\AcademicCalendar;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\CalendarException;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcademicCalendarService
{
    public function __construct(
        private TenantContext $tenantContext,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * Create or replace operating hours for one weekday in an academic period.
     */
    public function setOperatingHours(
        Organization $organization,
        AcademicPeriod $academicPeriod,
        int $weekday,
        int $startsAtMinute,
        int $endsAtMinute,
        ?User $actor = null,
    ): AcademicCalendar {
        return $this->tenantContext->run($organization, function () use ($organization, $academicPeriod, $weekday, $startsAtMinute, $endsAtMinute, $actor): AcademicCalendar {
            return DB::transaction(function () use ($organization, $academicPeriod, $weekday, $startsAtMinute, $endsAtMinute, $actor): AcademicCalendar {
                $this->lockOrganization($organization);
                $lockedPeriod = $this->lockPeriod($organization, $academicPeriod);
                $this->assertPeriodIsOpen($lockedPeriod);
                $this->assertWeekday($weekday);
                $this->assertTimeWindow($startsAtMinute, $endsAtMinute);

                $existingCalendar = AcademicCalendar::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('academic_period_id', $lockedPeriod->getKey())
                    ->where('weekday', $weekday)
                    ->lockForUpdate()
                    ->first();

                $calendar = AcademicCalendar::query()->updateOrCreate(
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

                $this->auditLogger->record(
                    action: 'academic_calendar.saved',
                    organization: $organization,
                    actor: $actor,
                    subject: $lockedPeriod,
                    before: $existingCalendar instanceof AcademicCalendar ? $this->calendarSnapshot($existingCalendar) : null,
                    after: $this->calendarSnapshot($calendar),
                );

                return $calendar;
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
        ?User $actor = null,
    ): CalendarException {
        return $this->tenantContext->run($organization, function () use ($organization, $academicPeriod, $date, $kind, $name, $startsAtMinute, $endsAtMinute, $actor): CalendarException {
            return DB::transaction(function () use ($organization, $academicPeriod, $date, $kind, $name, $startsAtMinute, $endsAtMinute, $actor): CalendarException {
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

                $before = $exception->exists ? $this->exceptionSnapshot($exception) : null;

                $exception->fill([
                    'kind' => $kind,
                    'name' => $name,
                    'starts_at_minute' => $startsAtMinute,
                    'ends_at_minute' => $endsAtMinute,
                ]);
                $exception->save();
                $exception->refresh();

                $this->auditLogger->record(
                    action: 'calendar_exception.saved',
                    organization: $organization,
                    actor: $actor,
                    subject: $lockedPeriod,
                    before: $before,
                    after: $this->exceptionSnapshot($exception),
                );

                return $exception;
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

    /**
     * @return array{weekday: int, starts_at_minute: int, ends_at_minute: int}
     */
    private function calendarSnapshot(AcademicCalendar $academicCalendar): array
    {
        return [
            'weekday' => $academicCalendar->weekday,
            'starts_at_minute' => $academicCalendar->starts_at_minute,
            'ends_at_minute' => $academicCalendar->ends_at_minute,
        ];
    }

    /**
     * @return array{date: string, kind: string, name: string, starts_at_minute: int|null, ends_at_minute: int|null}
     */
    private function exceptionSnapshot(CalendarException $calendarException): array
    {
        return [
            'date' => $calendarException->date->toDateString(),
            'kind' => $calendarException->kind->value,
            'name' => $calendarException->name,
            'starts_at_minute' => $calendarException->starts_at_minute,
            'ends_at_minute' => $calendarException->ends_at_minute,
        ];
    }
}
