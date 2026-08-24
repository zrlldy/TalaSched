<?php

namespace App\Resources;

use App\Enums\AvailabilityKind;
use App\Models\AcademicPeriod;
use App\Models\Organization;
use App\Models\ResourceAvailabilityRule;
use App\Models\SchedulingResource;
use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResourceAvailabilityService
{
    public function __construct(private TenantContext $tenantContext) {}

    public function create(
        Organization $organization,
        SchedulingResource $resource,
        AvailabilityKind $kind,
        int $weekday,
        int $startsAtMinute,
        int $endsAtMinute,
        ?AcademicPeriod $academicPeriod = null,
        ?CarbonInterface $effectiveFrom = null,
        ?CarbonInterface $effectiveUntil = null,
        int $priority = 0,
    ): ResourceAvailabilityRule {
        $this->assertTimeWindow($weekday, $startsAtMinute, $endsAtMinute);
        $this->assertEffectiveDates($effectiveFrom, $effectiveUntil);

        return $this->tenantContext->run($organization, function () use ($organization, $resource, $kind, $weekday, $startsAtMinute, $endsAtMinute, $academicPeriod, $effectiveFrom, $effectiveUntil, $priority): ResourceAvailabilityRule {
            return DB::transaction(function () use ($organization, $resource, $kind, $weekday, $startsAtMinute, $endsAtMinute, $academicPeriod, $effectiveFrom, $effectiveUntil, $priority): ResourceAvailabilityRule {
                Organization::query()
                    ->whereKey($organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedResource = SchedulingResource::query()
                    ->whereKey($resource->getKey())
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $periodId = null;

                if ($academicPeriod !== null) {
                    $periodId = AcademicPeriod::query()
                        ->whereKey($academicPeriod->getKey())
                        ->where('organization_id', $organization->getKey())
                        ->lockForUpdate()
                        ->firstOrFail()
                        ->getKey();
                }

                return ResourceAvailabilityRule::query()->create([
                    'organization_id' => $organization->getKey(),
                    'scheduling_resource_id' => $lockedResource->getKey(),
                    'academic_period_id' => $periodId,
                    'kind' => $kind,
                    'weekday' => $weekday,
                    'starts_at_minute' => $startsAtMinute,
                    'ends_at_minute' => $endsAtMinute,
                    'effective_from' => $effectiveFrom,
                    'effective_until' => $effectiveUntil,
                    'priority' => $priority,
                ]);
            }, attempts: 3);
        });
    }

    private function assertTimeWindow(int $weekday, int $startsAtMinute, int $endsAtMinute): void
    {
        if ($weekday < 1 || $weekday > 7 || $startsAtMinute < 0 || $endsAtMinute > 1440 || $startsAtMinute >= $endsAtMinute) {
            throw ValidationException::withMessages(['availability' => 'Availability rules must use a valid weekday time window.']);
        }
    }

    private function assertEffectiveDates(?CarbonInterface $effectiveFrom, ?CarbonInterface $effectiveUntil): void
    {
        if ($effectiveFrom !== null && $effectiveUntil !== null && $effectiveFrom->greaterThan($effectiveUntil)) {
            throw ValidationException::withMessages(['availability' => 'Availability rules must end on or after their effective start date.']);
        }
    }
}
