<?php

namespace App\Resources;

use App\Audit\AuditLogger;
use App\Enums\AvailabilityKind;
use App\Enums\ResourceType;
use App\Models\AcademicPeriod;
use App\Models\AcademicUnit;
use App\Models\FacultyProfile;
use App\Models\Organization;
use App\Models\ResourceAvailabilityRule;
use App\Models\SchedulingResource;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FacultyProfileService
{
    public function __construct(
        private TenantContext $tenantContext,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * Create a faculty profile and its canonical scheduling resource together.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(Organization $organization, string $resourceName, array $attributes = [], ?User $actor = null): FacultyProfile
    {
        $this->assertResourceName($resourceName);
        $this->assertLoadLimits(
            $attributes['maximum_daily_minutes'] ?? null,
            $attributes['maximum_weekly_minutes'] ?? null,
        );

        return $this->tenantContext->run($organization, function () use ($organization, $resourceName, $attributes, $actor): FacultyProfile {
            return DB::transaction(function () use ($organization, $resourceName, $attributes, $actor): FacultyProfile {
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

                $profile = $profile->fresh(['resource']);

                $this->auditLogger->record(
                    action: 'faculty_profile.created',
                    organization: $organization,
                    actor: $actor,
                    subject: $profile,
                    after: $this->profileSnapshot($profile),
                );

                return $profile;
            }, attempts: 3);
        });
    }

    /**
     * Update faculty metadata and load limits without changing tenant or resource ownership.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Organization $organization, FacultyProfile $profile, array $attributes, ?User $actor = null): FacultyProfile
    {
        $this->assertLoadLimits(
            $attributes['maximum_daily_minutes'] ?? $profile->maximum_daily_minutes,
            $attributes['maximum_weekly_minutes'] ?? $profile->maximum_weekly_minutes,
        );

        return $this->tenantContext->run($organization, function () use ($organization, $profile, $attributes, $actor): FacultyProfile {
            return DB::transaction(function () use ($organization, $profile, $attributes, $actor): FacultyProfile {
                $this->lockOrganization($organization);
                $lockedProfile = $this->lockProfile($organization, $profile);
                $lockedProfile->load('resource');
                $before = $this->profileSnapshot($lockedProfile);
                $lockedProfile->fill($this->profileAttributes($attributes));
                $lockedProfile->save();

                $lockedProfile = $lockedProfile->fresh(['resource']);

                $this->auditLogger->record(
                    action: 'faculty_profile.updated',
                    organization: $organization,
                    actor: $actor,
                    subject: $lockedProfile,
                    before: $before,
                    after: $this->profileSnapshot($lockedProfile),
                );

                return $lockedProfile;
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
        ?User $actor = null,
    ): FacultyProfile {
        return $this->tenantContext->run($organization, function () use ($organization, $profile, $academicUnit, $isPrimary, $actor): FacultyProfile {
            return DB::transaction(function () use ($organization, $profile, $academicUnit, $isPrimary, $actor): FacultyProfile {
                $this->lockOrganization($organization);
                $lockedProfile = $this->lockProfile($organization, $profile);
                $lockedUnit = AcademicUnit::query()
                    ->whereKey($academicUnit->getKey())
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $beforePrimary = DB::table('faculty_unit_assignments')
                    ->where('organization_id', $organization->getKey())
                    ->where('faculty_profile_id', $lockedProfile->getKey())
                    ->where('academic_unit_id', $lockedUnit->getKey())
                    ->value('is_primary');

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

                $this->auditLogger->record(
                    action: 'faculty_profile.academic_unit_assigned',
                    organization: $organization,
                    actor: $actor,
                    subject: $lockedProfile,
                    before: $beforePrimary === null ? null : ['academic_unit_id' => $lockedUnit->public_id, 'is_primary' => (bool) $beforePrimary],
                    after: ['academic_unit_id' => $lockedUnit->public_id, 'is_primary' => $isPrimary],
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
        ?User $actor = null,
    ): FacultyProfile {
        return $this->tenantContext->run($organization, function () use ($organization, $profile, $academicUnit, $actor): FacultyProfile {
            return DB::transaction(function () use ($organization, $profile, $academicUnit, $actor): FacultyProfile {
                $this->lockOrganization($organization);
                $lockedProfile = $this->lockProfile($organization, $profile);
                $lockedUnit = AcademicUnit::query()
                    ->whereKey($academicUnit->getKey())
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $deleted = DB::table('faculty_unit_assignments')
                    ->where('organization_id', $organization->getKey())
                    ->where('faculty_profile_id', $lockedProfile->getKey())
                    ->where('academic_unit_id', $lockedUnit->getKey())
                    ->delete();

                if ($deleted === 1) {
                    $this->auditLogger->record(
                        action: 'faculty_profile.academic_unit_removed',
                        organization: $organization,
                        actor: $actor,
                        subject: $lockedProfile,
                        before: ['academic_unit_id' => $lockedUnit->public_id],
                    );
                }

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
        ?User $actor = null,
    ): ResourceAvailabilityRule {
        $this->assertTimeWindow($weekday, $startsAtMinute, $endsAtMinute);
        $this->assertEffectiveDates($effectiveFrom, $effectiveUntil);

        return $this->tenantContext->run($organization, function () use ($organization, $profile, $kind, $weekday, $startsAtMinute, $endsAtMinute, $academicPeriod, $effectiveFrom, $effectiveUntil, $priority, $actor): ResourceAvailabilityRule {
            return DB::transaction(function () use ($organization, $profile, $kind, $weekday, $startsAtMinute, $endsAtMinute, $academicPeriod, $effectiveFrom, $effectiveUntil, $priority, $actor): ResourceAvailabilityRule {
                $this->lockOrganization($organization);
                $lockedProfile = $this->lockProfile($organization, $profile);
                $lockedPeriod = null;

                if ($academicPeriod !== null) {
                    $lockedPeriod = AcademicPeriod::query()
                        ->whereKey($academicPeriod->getKey())
                        ->where('organization_id', $organization->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                $availabilityRule = ResourceAvailabilityRule::query()->create([
                    'organization_id' => $organization->getKey(),
                    'scheduling_resource_id' => $lockedProfile->scheduling_resource_id,
                    'academic_period_id' => $lockedPeriod?->getKey(),
                    'kind' => $kind,
                    'weekday' => $weekday,
                    'starts_at_minute' => $startsAtMinute,
                    'ends_at_minute' => $endsAtMinute,
                    'effective_from' => $effectiveFrom,
                    'effective_until' => $effectiveUntil,
                    'priority' => $priority,
                ]);

                $this->auditLogger->record(
                    action: 'faculty_profile.availability_rule_created',
                    organization: $organization,
                    actor: $actor,
                    subject: $lockedProfile,
                    after: $this->availabilityRuleSnapshot($availabilityRule, $lockedPeriod),
                );

                return $availabilityRule;
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
        ?User $actor = null,
    ): void {
        $this->tenantContext->run($organization, function () use ($organization, $profile, $rule, $actor): void {
            DB::transaction(function () use ($organization, $profile, $rule, $actor): void {
                $this->lockOrganization($organization);
                $lockedProfile = $this->lockProfile($organization, $profile);
                $lockedRule = ResourceAvailabilityRule::query()
                    ->whereKey($rule->getKey())
                    ->where('organization_id', $organization->getKey())
                    ->where('scheduling_resource_id', $lockedProfile->scheduling_resource_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->auditLogger->record(
                    action: 'faculty_profile.availability_rule_removed',
                    organization: $organization,
                    actor: $actor,
                    subject: $lockedProfile,
                    before: $this->availabilityRuleSnapshot($lockedRule),
                );

                $lockedRule->delete();
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

    /**
     * @return array{id: string, resource_id: string, position: string|null, employment_type: string|null, maximum_daily_minutes: int|null, maximum_weekly_minutes: int|null}
     */
    private function profileSnapshot(FacultyProfile $profile): array
    {
        return [
            'id' => $profile->public_id,
            'resource_id' => $profile->resource->public_id,
            'position' => $profile->position,
            'employment_type' => $profile->employment_type?->value,
            'maximum_daily_minutes' => $profile->maximum_daily_minutes,
            'maximum_weekly_minutes' => $profile->maximum_weekly_minutes,
        ];
    }

    /**
     * @return array{kind: string, weekday: int, starts_at_minute: int, ends_at_minute: int, academic_period_id: string|null, effective_from: string|null, effective_until: string|null, priority: int}
     */
    private function availabilityRuleSnapshot(ResourceAvailabilityRule $availabilityRule, ?AcademicPeriod $academicPeriod = null): array
    {
        return [
            'kind' => $availabilityRule->kind->value,
            'weekday' => $availabilityRule->weekday,
            'starts_at_minute' => $availabilityRule->starts_at_minute,
            'ends_at_minute' => $availabilityRule->ends_at_minute,
            'academic_period_id' => $academicPeriod?->public_id,
            'effective_from' => $availabilityRule->effective_from?->toDateString(),
            'effective_until' => $availabilityRule->effective_until?->toDateString(),
            'priority' => $availabilityRule->priority,
        ];
    }
}
