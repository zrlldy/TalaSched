<?php

use App\Enums\AcademicPeriodKind;
use App\Enums\AvailabilityKind;
use App\Models\AcademicCalendar;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Organization;
use App\Models\ResourceAvailabilityRule;
use App\Models\SchedulingResource;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use App\Scheduling\ResourceAvailabilityResolver;

test('availability resolver applies calendars, scoped windows, hard blocks, and soft preferences', function (): void {
    $organization = Organization::factory()->create();
    $year = AcademicYear::factory()->forOrganization($organization)->create([
        'starts_on' => '2026-08-01',
        'ends_on' => '2027-07-31',
    ]);
    $period = AcademicPeriod::factory()->forAcademicYear($year)->create([
        'kind' => AcademicPeriodKind::Semester,
        'starts_on' => '2026-08-01',
        'ends_on' => '2026-12-20',
    ]);
    $timetable = Timetable::factory()->state([
        'academic_period_id' => $period->getKey(),
        'organization_id' => $organization->getKey(),
        'academic_year_id' => $year->getKey(),
    ])->create();
    $version = TimetableVersion::factory()->state([
        'timetable_id' => $timetable->getKey(),
        'organization_id' => $organization->getKey(),
    ])->create();
    $resource = SchedulingResource::factory()->forOrganization($organization)->faculty()->create();
    AcademicCalendar::factory()->forAcademicPeriod($period)->create([
        'weekday' => 1,
        'starts_at_minute' => 480,
        'ends_at_minute' => 960,
    ]);
    ResourceAvailabilityRule::factory()->forResource($resource)->create([
        'kind' => AvailabilityKind::Available,
        'weekday' => 1,
        'starts_at_minute' => 600,
        'ends_at_minute' => 840,
    ]);
    ResourceAvailabilityRule::factory()->forResource($resource)->forPeriod($period)->create([
        'kind' => AvailabilityKind::Available,
        'weekday' => 1,
        'starts_at_minute' => 720,
        'ends_at_minute' => 900,
    ]);
    ResourceAvailabilityRule::factory()->forResource($resource)->create([
        'kind' => AvailabilityKind::Unavailable,
        'weekday' => 1,
        'starts_at_minute' => 780,
        'ends_at_minute' => 810,
    ]);
    ResourceAvailabilityRule::factory()->forResource($resource)->create([
        'kind' => AvailabilityKind::Preferred,
        'weekday' => 1,
        'starts_at_minute' => 840,
        'ends_at_minute' => 900,
    ]);
    ResourceAvailabilityRule::factory()->forResource($resource)->create([
        'kind' => AvailabilityKind::Avoid,
        'weekday' => 1,
        'starts_at_minute' => 600,
        'ends_at_minute' => 660,
    ]);
    ResourceAvailabilityRule::factory()->forResource($resource)->create([
        'kind' => AvailabilityKind::Unavailable,
        'weekday' => 1,
        'starts_at_minute' => 480,
        'ends_at_minute' => 600,
        'effective_from' => '2026-07-01',
        'effective_until' => '2026-07-31',
    ]);
    $resolver = app(ResourceAvailabilityResolver::class);

    $blocked = $resolver->issues($organization, $version, collect([$resource]), 1, 780, 810);
    $outsidePreferred = $resolver->issues($organization, $version, collect([$resource]), 1, 960, 990);
    $outsideSpecificWindow = $resolver->issues($organization, $version, collect([$resource]), 1, 600, 630);

    expect($blocked)->toHaveCount(2)
        ->and(collect($blocked)->pluck('code')->all())->toBe(['resource_unavailable', 'resource_preference'])
        ->and(collect($blocked)->pluck('severity')->all())->toBe(['hard', 'soft'])
        ->and($outsidePreferred)->toHaveCount(3)
        ->and(collect($outsidePreferred)->where('severity', 'hard')->count())->toBe(2)
        ->and(collect($outsidePreferred)->where('severity', 'soft')->count())->toBe(1)
        ->and($outsideSpecificWindow)->toHaveCount(3)
        ->and(collect($outsideSpecificWindow)->where('severity', 'hard')->count())->toBe(1)
        ->and(collect($outsideSpecificWindow)->where('severity', 'soft')->count())->toBe(2);
});
