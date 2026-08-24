<?php

namespace App\Resources;

use App\Enums\AvailabilityKind;
use App\Enums\ResourceType;
use App\Models\AcademicPeriod;
use App\Models\AcademicUnit;
use App\Models\FacultyProfile;
use App\Models\Organization;
use App\Models\ResourceAvailabilityRule;
use App\Models\SchedulingResource;
use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FacultyProfileService
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * Create a faculty profile and its canonical scheduling resource together.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(Organization $organization, string $resourceName, array $attributes = []): FacultyProfile
    {
        $this->assertResourceName($resourceName);
        $this->assertLoadLimits(
            $attributes['maximum_daily_minutes'] ?? null,
            $attributes['maximum_weekly_minutes'] ?? null,
        );

        return $this->tenantContext->run($organization, function () use ($organization, $resourceName, $attributes): FacultyProfile {
            return DB::transaction(function () use ($organization, $resourceName, $attributes): FacultyProfile {
                $this->lockOrganization($organization);

                $resource = SchedulingResource::query()->create([
                    'organization_id' => $organization->getKey(),
                    'type' => ResourceType::Faculty,
                    'name' => $resourceName,
                    'is_active' => true,
                ]);

                $profile = new FacultyProfile([
                    'organization_id' => $organization->getKey(),
                    'scheduling_resource_id' => $resource->getKey(),
                ]);
                $profile->fill($this->profileAttributes($attributes));
                $profile->save();

                return $profile->fresh(['resource']);
            }, attempts: 3);
        });
    }

    /**
     * Update faculty metadata and load limits without changing tenant or resource ownership.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Organization $organization, FacultyProfile $profile, array $attributes): FacultyProfile
    {
        $this->assertLoadLimits(
            $attributes['maximum_daily_minutes'] ?? $profile->maximum_daily_minutes,
            $attributes['maximum_weekly_minutes'] ?? $profile->maximum_weekly_minutes,
        );

        return $this->tenantContext->run($organization, function () use ($organization, $profile, $attributes): FacultyProfile {
            return DB::transaction(function () use ($organization, $profile, $attributes): FacultyProfile {
                $this->lockOrganization($organization);
                $lockedProfile = $this->lockProfile($organization, $profile);
                $lockedProfile->fill($this->profileAttributes($attributes));
                $lockedProfile->save();

                return $lockedProfile->fresh(['resource']);
            }, attempts: 3);
        });
    }

    /**
     * Assign a faculty member to an organization-local academic unit.
     */
    public function assignToAcademicUnit(
        Organization $organization,
        FacultyProfile $profile,
        AcademicUnit $academicUnit,
        bool $isPrimary = false,
    ): FacultyProfile {
        return $this->tenantContext->run($organization, function () use ($organization, $profile, $academicUnit, $isPrimary): FacultyProfile {
            return DB::transaction(function () use ($organization, $profile, $academicUnit, $isPrimary): FacultyProfile {
                $this->lockOrganization($organization);
                $lockedProfile = $this->lockProfile($organization, $profile);
                $lockedUnit = AcademicUnit::query()
                    ->whereKey($academicUnit->getKey())
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($isPrimary) {
                    DB::table('faculty_unit_assignments')
                        ->where('organization_id', $organization->getKey())
                        ->where('faculty_profile_id', $lockedProfile->getKey())
                        ->update(['is_primary' => false]);
                }

                DB::table('faculty_unit_assignments')->updateOrInsert(
                    [
                        'organization_id' => $organization->getKey(),
                        'faculty_profile_id' => $lockedProfile->getKey(),
                        'academic_unit_id' => $lockedUnit->getKey(),
                    ],
                    ['is_primary' => $isPrimary],
                );

                return $lockedProfile->fresh(['academicUnits']);
            }, attempts: 3);
        });
    }

    /**
     * Remove a faculty member's link to an organization-local academic unit.
     */
    public function removeFromAcademicUnit(
        Organization $organization,
        FacultyProfile $profile,
        AcademicUnit $academicUnit,
    ): FacultyProfile {
        return $this->tenantContext->run($organization, function () use ($organization, $profile, $academicUnit): FacultyProfile {
            return DB::transaction(function () use ($organization, $profile, $academicUnit): FacultyProfile {
                $this->lockOrganization($organization);
                $lockedProfile = $this->lockProfile($organization, $profile);

                DB::table('faculty_unit_assignments')
                    ->where('organization_id', $organization->getKey())
                    ->where('faculty_profile_id', $lockedProfile->getKey())
                    ->where('academic_unit_id', $academicUnit->getKey())
                    ->delete();

                return $lockedProfile->fresh(['academicUnits']);
            }, attempts: 3);
        });
    }

    /**
     * Add an availability or preference rule for a faculty member.
     */
    public function setAvailabilityRule(
        Organization $organization,
        FacultyProfile $profile,
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

        return $this->tenantContext->run($organization, function () use ($organization, $profile, $kind, $weekday, $startsAtMinute, $endsAtMinute, $academicPeriod, $effectiveFrom, $effectiveUntil, $priority): ResourceAvailabilityRule {
            return DB::transaction(function () use ($organization, $profile, $kind, $weekday, $startsAtMinute, $endsAtMinute, $academicPeriod, $effectiveFrom, $effectiveUntil, $priority): ResourceAvailabilityRule {
                $this->lockOrganization($organization);
                $lockedProfile = $this->lockProfile($organization, $profile);
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
                    'scheduling_resource_id' => $lockedProfile->scheduling_resource_id,
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

    /**
     * Remove one availability or preference rule owned by the faculty resource.
     */
    public function removeAvailabilityRule(
        Organization $organization,
        FacultyProfile $profile,
        ResourceAvailabilityRule $rule,
    ): void {
        $this->tenantContext->run($organization, function () use ($organization, $profile, $rule): void {
            DB::transaction(function () use ($organization, $profile, $rule): void {
                $this->lockOrganization($organization);
                $lockedProfile = $this->lockProfile($organization, $profile);

                ResourceAvailabilityRule::query()
                    ->whereKey($rule->getKey())
                    ->where('organization_id', $organization->getKey())
                    ->where('scheduling_resource_id', $lockedProfile->scheduling_resource_id)
                    ->delete();
            }, attempts: 3);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function profileAttributes(array $attributes): array
    {
        return Arr::except($attributes, ['organization_id', 'scheduling_resource_id']);
    }

    private function lockOrganization(Organization $organization): Organization
    {
        return Organization::query()
            ->whereKey($organization->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockProfile(Organization $organization, FacultyProfile $profile): FacultyProfile
    {
        return FacultyProfile::query()
            ->whereKey($profile->getKey())
            ->where('organization_id', $organization->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertResourceName(string $resourceName): void
    {
        if (trim($resourceName) === '') {
            throw ValidationException::withMessages(['resource_name' => 'A faculty resource name is required.']);
        }
    }

    private function assertLoadLimits(mixed $maximumDailyMinutes, mixed $maximumWeeklyMinutes): void
    {
        if (($maximumDailyMinutes !== null && (! is_int($maximumDailyMinutes) || $maximumDailyMinutes < 1))
            || ($maximumWeeklyMinutes !== null && (! is_int($maximumWeeklyMinutes) || $maximumWeeklyMinutes < 1))) {
            throw ValidationException::withMessages(['load_limits' => 'Faculty load limits must be positive when provided.']);
        }

        if ($maximumDailyMinutes !== null && $maximumWeeklyMinutes !== null && $maximumDailyMinutes > $maximumWeeklyMinutes) {
            throw ValidationException::withMessages(['load_limits' => 'A faculty daily load limit cannot exceed the weekly load limit.']);
        }
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
