<?php

use App\Enums\AcademicPeriodKind;
use App\Enums\AvailabilityKind;
use App\Enums\ResourceType;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Organization;
use App\Models\ResourceAvailabilityRule;
use App\Models\SchedulingResource;
use App\Resources\ResourceAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

test('availability service creates tenant-scoped rules for any active resource', function (): void {
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
    $resource = SchedulingResource::factory()->forOrganization($organization)->room()->create();
    $service = app(ResourceAvailabilityService::class);

    $rule = $service->create(
        $organization,
        $resource,
        AvailabilityKind::Available,
        1,
        480,
        1020,
        $period,
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-12-20'),
        4,
    );

    expect($rule->resource->is($resource))->toBeTrue()
        ->and($rule->academicPeriod->is($period))->toBeTrue()
        ->and($rule->kind)->toBe(AvailabilityKind::Available)
        ->and($rule->priority)->toBe(4)
        ->and(ResourceAvailabilityRule::query()->where('organization_id', $organization->getKey())->count())->toBe(1);
});

test('availability service rejects invalid windows and foreign resources', function (): void {
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $resource = SchedulingResource::factory()->forOrganization($organization)->state([
        'type' => ResourceType::Room,
    ])->create();
    $foreignResource = SchedulingResource::factory()->forOrganization($foreignOrganization)->create();
    $service = app(ResourceAvailabilityService::class);

    expect(fn () => $service->create($organization, $resource, AvailabilityKind::Available, 1, 600, 600))
        ->toThrow(ValidationException::class, 'weekday')
        ->and(fn () => $service->create($organization, $foreignResource, AvailabilityKind::Available, 1, 480, 600))
        ->toThrow(ModelNotFoundException::class);
});
