<?php

namespace App\Academic;

use App\Enums\AcademicPeriodKind;
use App\Enums\AcademicYearStatus;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcademicYearLifecycleService
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * Create a draft academic year for an organization.
     */
    public function createYear(
        Organization $organization,
        string $name,
        CarbonInterface $startsOn,
        CarbonInterface $endsOn,
    ): AcademicYear {
        return $this->tenantContext->run($organization, function () use ($organization, $name, $startsOn, $endsOn): AcademicYear {
            return DB::transaction(function () use ($organization, $name, $startsOn, $endsOn): AcademicYear {
                $this->lockOrganization($organization);

                return AcademicYear::query()->create([
                    'organization_id' => $organization->getKey(),
                    'name' => $name,
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                    'status' => AcademicYearStatus::Draft,
                ]);
            }, attempts: 3);
        });
    }

    /**
     * Add a non-overlapping period to a draft academic year.
     */
    public function createPeriod(
        Organization $organization,
        AcademicYear $academicYear,
        string $name,
        AcademicPeriodKind $kind,
        int $sequence,
        CarbonInterface $startsOn,
        CarbonInterface $endsOn,
    ): AcademicPeriod {
        return $this->tenantContext->run($organization, function () use ($organization, $academicYear, $name, $kind, $sequence, $startsOn, $endsOn): AcademicPeriod {
            return DB::transaction(function () use ($organization, $academicYear, $name, $kind, $sequence, $startsOn, $endsOn): AcademicPeriod {
                $this->lockOrganization($organization);
                $lockedYear = $this->lockYear($organization, $academicYear);
                $this->assertDraft($lockedYear);
                $this->assertNoOverlappingPeriod($organization, $lockedYear, $startsOn, $endsOn);

                return AcademicPeriod::query()->create([
                    'organization_id' => $organization->getKey(),
                    'academic_year_id' => $lockedYear->getKey(),
                    'name' => $name,
                    'kind' => $kind,
                    'sequence' => $sequence,
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                ]);
            }, attempts: 3);
        });
    }

    /**
     * Activate a draft year once its configurable periods are complete.
     */
    public function activate(Organization $organization, AcademicYear $academicYear): AcademicYear
    {
        return $this->tenantContext->run($organization, function () use ($organization, $academicYear): AcademicYear {
            return DB::transaction(function () use ($organization, $academicYear): AcademicYear {
                $this->lockOrganization($organization);
                $lockedYear = $this->lockYear($organization, $academicYear);

                if ($lockedYear->status === AcademicYearStatus::Active) {
                    return $lockedYear->fresh();
                }

                $this->assertDraft($lockedYear);
                $periods = AcademicPeriod::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('academic_year_id', $lockedYear->getKey())
                    ->orderBy('sequence')
                    ->lockForUpdate()
                    ->get();

                if ($periods->isEmpty()) {
                    throw ValidationException::withMessages([
                        'academic_year' => __('An academic year must have at least one period before activation.'),
                    ]);
                }

                foreach ($periods as $index => $period) {
                    if ($period->sequence !== $index + 1) {
                        throw ValidationException::withMessages([
                            'periods' => __('Academic period sequences must be contiguous and start at one.'),
                        ]);
                    }
                }

                $overlappingYearExists = AcademicYear::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKeyNot($lockedYear->getKey())
                    ->where('status', AcademicYearStatus::Active->value)
                    ->where('starts_on', '<=', $lockedYear->ends_on->toDateString())
                    ->where('ends_on', '>=', $lockedYear->starts_on->toDateString())
                    ->lockForUpdate()
                    ->exists();

                if ($overlappingYearExists) {
                    throw ValidationException::withMessages([
                        'academic_year' => __('An active academic year already overlaps these dates.'),
                    ]);
                }

                $lockedYear->update(['status' => AcademicYearStatus::Active]);

                return $lockedYear->fresh();
            }, attempts: 3);
        });
    }

    /**
     * Close an active academic year without changing its historical periods.
     */
    public function close(Organization $organization, AcademicYear $academicYear): AcademicYear
    {
        return $this->tenantContext->run($organization, function () use ($organization, $academicYear): AcademicYear {
            return DB::transaction(function () use ($organization, $academicYear): AcademicYear {
                $this->lockOrganization($organization);
                $lockedYear = $this->lockYear($organization, $academicYear);

                if ($lockedYear->status === AcademicYearStatus::Closed) {
                    return $lockedYear->fresh();
                }

                if ($lockedYear->status !== AcademicYearStatus::Active) {
                    throw ValidationException::withMessages([
                        'academic_year' => __('Only an active academic year can be closed.'),
                    ]);
                }

                $lockedYear->update(['status' => AcademicYearStatus::Closed]);

                return $lockedYear->fresh();
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

    private function lockYear(Organization $organization, AcademicYear $academicYear): AcademicYear
    {
        return AcademicYear::query()
            ->whereKey($academicYear->getKey())
            ->where('organization_id', $organization->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertDraft(AcademicYear $academicYear): void
    {
        if ($academicYear->status === AcademicYearStatus::Draft) {
            return;
        }

        throw ValidationException::withMessages([
            'academic_year' => __('Only draft academic years can be configured.'),
        ]);
    }

    private function assertNoOverlappingPeriod(
        Organization $organization,
        AcademicYear $academicYear,
        CarbonInterface $startsOn,
        CarbonInterface $endsOn,
    ): void {
        $overlaps = AcademicPeriod::query()
            ->where('organization_id', $organization->getKey())
            ->where('academic_year_id', $academicYear->getKey())
            ->where('starts_on', '<=', $endsOn->toDateString())
            ->where('ends_on', '>=', $startsOn->toDateString())
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages([
                'periods' => __('Academic periods in one year cannot overlap.'),
            ]);
        }
    }
}
