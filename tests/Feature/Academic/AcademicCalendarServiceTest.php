<?php

use App\Academic\AcademicCalendarService;
use App\Academic\AcademicYearLifecycleService;
use App\Enums\AcademicPeriodKind;
use App\Enums\CalendarExceptionKind;
use App\Models\AcademicCalendar;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\CalendarException;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

test('academic calendars manage operating hours and date-specific exceptions', function (): void {
    $organization = Organization::factory()->create();
    $academicYear = AcademicYear::factory()->forOrganization($organization)->create([
        'starts_on' => '2026-08-01',
        'ends_on' => '2027-07-31',
    ]);
    $academicPeriod = AcademicPeriod::factory()->forAcademicYear($academicYear)->create([
        'kind' => AcademicPeriodKind::Custom,
        'starts_on' => '2026-08-01',
        'ends_on' => '2027-07-31',
    ]);
    $service = app(AcademicCalendarService::class);

    $calendar = $service->setOperatingHours($organization, $academicPeriod, 1, 480, 960);
    $holiday = $service->setException(
        $organization,
        $academicPeriod,
        CarbonImmutable::parse('2026-08-21'),
        CalendarExceptionKind::Holiday,
        'Foundation day',
    );
    $teachingDate = $service->setException(
        $organization,
        $academicPeriod,
        CarbonImmutable::parse('2026-08-29'),
        CalendarExceptionKind::Teaching,
        'Saturday make-up class',
        540,
        720,
    );

    $calendar->academicPeriod->load('calendarExceptions');

    expect($calendar->starts_at_minute)->toBeInt()
        ->and($calendar->academicPeriod->is($academicPeriod))->toBeTrue()
        ->and($holiday->kind)->toBe(CalendarExceptionKind::Holiday)
        ->and($teachingDate->kind)->toBe(CalendarExceptionKind::Teaching)
        ->and($calendar->academicPeriod->calendarExceptions)->toHaveCount(2);

    $updatedCalendar = $service->setOperatingHours($organization, $academicPeriod, 1, 600, 1020);
    $updatedException = $service->setException(
        $organization,
        $academicPeriod,
        CarbonImmutable::parse('2026-08-21'),
        CalendarExceptionKind::Blocked,
        'Maintenance day',
    );

    expect(AcademicCalendar::query()->where('academic_period_id', $academicPeriod->getKey())->count())->toBe(1)
        ->and(CalendarException::query()->where('academic_period_id', $academicPeriod->getKey())->count())->toBe(2)
        ->and($updatedCalendar->starts_at_minute)->toBe(600)
        ->and($updatedException->kind)->toBe(CalendarExceptionKind::Blocked)
        ->and($updatedException->name)->toBe('Maintenance day');
});

test('academic calendar rules reject invalid windows, dates, and closed periods', function (): void {
    $organization = Organization::factory()->create();
    $academicYear = AcademicYear::factory()->forOrganization($organization)->create([
        'starts_on' => '2026-08-01',
        'ends_on' => '2027-07-31',
    ]);
    $academicPeriod = AcademicPeriod::factory()->forAcademicYear($academicYear)->create([
        'starts_on' => '2026-08-01',
        'ends_on' => '2027-07-31',
    ]);
    $service = app(AcademicCalendarService::class);

    expect(fn () => $service->setOperatingHours($organization, $academicPeriod, 8, 480, 960))
        ->toThrow(ValidationException::class)
        ->and(fn () => $service->setOperatingHours($organization, $academicPeriod, 1, 960, 480))
        ->toThrow(ValidationException::class)
        ->and(fn () => $service->setException(
            $organization,
            $academicPeriod,
            CarbonImmutable::parse('2027-08-01'),
            CalendarExceptionKind::Holiday,
            'Outside period',
        ))->toThrow(ValidationException::class)
        ->and(fn () => $service->setException(
            $organization,
            $academicPeriod,
            CarbonImmutable::parse('2026-08-21'),
            CalendarExceptionKind::Holiday,
            'Partial holiday',
            480,
            600,
        ))->toThrow(ValidationException::class)
        ->and(fn () => $service->setException(
            $organization,
            $academicPeriod,
            CarbonImmutable::parse('2026-08-21'),
            CalendarExceptionKind::Teaching,
            'Incomplete window',
            480,
        ))->toThrow(ValidationException::class);

    $lifecycle = app(AcademicYearLifecycleService::class);
    $activeYear = $lifecycle->activate($organization, $academicYear);
    $closedYear = $lifecycle->close($organization, $activeYear);

    expect($closedYear->status->value)->toBe('closed')
        ->and(fn () => $service->setOperatingHours($organization, $academicPeriod, 1, 480, 960))
        ->toThrow(ValidationException::class);
});

test('academic calendar mutations reject periods from another organization', function (): void {
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $foreignYear = AcademicYear::factory()->forOrganization($foreignOrganization)->create();
    $foreignPeriod = AcademicPeriod::factory()->forAcademicYear($foreignYear)->create();
    $service = app(AcademicCalendarService::class);

    expect(fn () => $service->setOperatingHours($organization, $foreignPeriod, 1, 480, 960))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => $service->setException(
            $organization,
            $foreignPeriod,
            CarbonImmutable::parse('2026-08-21'),
            CalendarExceptionKind::Holiday,
            'Foreign holiday',
        ))->toThrow(ModelNotFoundException::class);
});
