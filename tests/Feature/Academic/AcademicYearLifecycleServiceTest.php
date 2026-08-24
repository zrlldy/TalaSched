<?php

use App\Academic\AcademicYearLifecycleService;
use App\Enums\AcademicPeriodKind;
use App\Enums\AcademicYearStatus;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

test('academic years support configurable period kinds and a guarded lifecycle', function (): void {
    $organization = Organization::factory()->create();
    $service = app(AcademicYearLifecycleService::class);
    $year = $service->createYear(
        organization: $organization,
        name: '2026-2027',
        startsOn: CarbonImmutable::parse('2026-08-01'),
        endsOn: CarbonImmutable::parse('2027-07-31'),
    );

    $service->createPeriod($organization, $year, 'First period', AcademicPeriodKind::Custom, 1, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-11-30'));
    $service->createPeriod($organization, $year, 'Second period', AcademicPeriodKind::Quarter, 2, CarbonImmutable::parse('2026-12-01'), CarbonImmutable::parse('2027-03-31'));
    $service->createPeriod($organization, $year, 'Summer period', AcademicPeriodKind::Summer, 3, CarbonImmutable::parse('2027-04-01'), CarbonImmutable::parse('2027-07-31'));

    $activeYear = $service->activate($organization, $year);
    $activeYear->load('periods');

    expect($year->status)->toBe(AcademicYearStatus::Draft)
        ->and($activeYear->status)->toBe(AcademicYearStatus::Active)
        ->and($activeYear->periods->pluck('kind')->all())->toEqual([
            AcademicPeriodKind::Custom,
            AcademicPeriodKind::Quarter,
            AcademicPeriodKind::Summer,
        ])
        ->and(fn () => $service->createPeriod(
            $organization,
            $year,
            'Late period',
            AcademicPeriodKind::Term,
            4,
            CarbonImmutable::parse('2027-07-01'),
            CarbonImmutable::parse('2027-07-15'),
        ))->toThrow(ValidationException::class);

    $closedYear = $service->close($organization, $activeYear);

    expect($closedYear->status)->toBe(AcademicYearStatus::Closed)
        ->and($service->close($organization, $closedYear)->status)->toBe(AcademicYearStatus::Closed);
});

test('academic year activation requires periods with contiguous sequences and rejects overlaps', function (): void {
    $organization = Organization::factory()->create();
    $service = app(AcademicYearLifecycleService::class);
    $year = $service->createYear(
        $organization,
        '2026-2027',
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2027-07-31'),
    );

    expect(fn () => $service->activate($organization, $year))->toThrow(ValidationException::class);

    $service->createPeriod($organization, $year, 'Second period', AcademicPeriodKind::Term, 2, CarbonImmutable::parse('2027-01-01'), CarbonImmutable::parse('2027-07-31'));

    expect(fn () => $service->activate($organization, $year))->toThrow(ValidationException::class);

    $overlappingYear = $service->createYear(
        $organization,
        '2027-2028',
        CarbonImmutable::parse('2027-08-01'),
        CarbonImmutable::parse('2028-07-31'),
    );
    $service->createPeriod($organization, $overlappingYear, 'First period', AcademicPeriodKind::Term, 1, CarbonImmutable::parse('2027-08-01'), CarbonImmutable::parse('2027-12-31'));
    $service->createPeriod($organization, $overlappingYear, 'Second period', AcademicPeriodKind::Term, 2, CarbonImmutable::parse('2028-01-01'), CarbonImmutable::parse('2028-07-31'));
    $service->activate($organization, $overlappingYear);

    $nextYear = $service->createYear(
        $organization,
        '2028-2029',
        CarbonImmutable::parse('2028-01-01'),
        CarbonImmutable::parse('2028-12-31'),
    );

    $service->createPeriod($organization, $nextYear, 'First period', AcademicPeriodKind::Term, 1, CarbonImmutable::parse('2028-01-01'), CarbonImmutable::parse('2028-06-30'));
    $service->createPeriod($organization, $nextYear, 'Second period', AcademicPeriodKind::Term, 2, CarbonImmutable::parse('2028-07-01'), CarbonImmutable::parse('2028-12-31'));

    expect(fn () => $service->activate($organization, $nextYear))->toThrow(ValidationException::class);
});

test('academic year period creation rejects overlapping dates and foreign years', function (): void {
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $service = app(AcademicYearLifecycleService::class);
    $year = $service->createYear(
        $organization,
        '2026-2027',
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2027-07-31'),
    );
    $foreignYear = $service->createYear(
        $foreignOrganization,
        '2026-2027',
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2027-07-31'),
    );

    $service->createPeriod($organization, $year, 'First period', AcademicPeriodKind::Term, 1, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-12-31'));

    expect(fn () => $service->createPeriod($organization, $year, 'Overlapping period', AcademicPeriodKind::Term, 2, CarbonImmutable::parse('2026-12-01'), CarbonImmutable::parse('2027-03-31')))
        ->toThrow(ValidationException::class)
        ->and(fn () => $service->createPeriod($organization, $foreignYear, 'Foreign period', AcademicPeriodKind::Term, 1, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-12-31')))
        ->toThrow(ModelNotFoundException::class);
});
