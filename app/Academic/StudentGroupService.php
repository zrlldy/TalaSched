<?php

namespace App\Academic;

use App\Enums\AcademicYearStatus;
use App\Models\AcademicPeriod;
use App\Models\AcademicUnit;
use App\Models\AcademicYear;
use App\Models\Organization;
use App\Models\StudentGroup;
use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudentGroupService
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * Set the date range in which a student group is active.
     */
    public function setActiveDates(
        Organization $organization,
        StudentGroup $studentGroup,
        ?CarbonInterface $activeFrom,
        ?CarbonInterface $activeUntil,
    ): StudentGroup {
        return $this->tenantContext->run($organization, function () use ($organization, $studentGroup, $activeFrom, $activeUntil): StudentGroup {
            return DB::transaction(function () use ($organization, $studentGroup, $activeFrom, $activeUntil): StudentGroup {
                $this->lockOrganization($organization);
                $lockedGroup = $this->lockGroup($organization, $studentGroup);
                $academicYear = $this->lockYear($organization, $lockedGroup);
                $this->assertYearIsOpen($academicYear);
                $this->assertActiveDates($academicYear, $activeFrom, $activeUntil);

                $lockedGroup->update([
                    'active_from' => $activeFrom,
                    'active_until' => $activeUntil,
                ]);

                return $lockedGroup->fresh();
            }, attempts: 3);
        });
    }

    /**
     * Move a group to another organization-local academic unit.
     */
    public function assignToUnit(
        Organization $organization,
        StudentGroup $studentGroup,
        AcademicUnit $academicUnit,
    ): StudentGroup {
        return $this->tenantContext->run($organization, function () use ($organization, $studentGroup, $academicUnit): StudentGroup {
            return DB::transaction(function () use ($organization, $studentGroup, $academicUnit): StudentGroup {
                $this->lockOrganization($organization);
                $lockedGroup = $this->lockGroup($organization, $studentGroup);
                $academicYear = $this->lockYear($organization, $lockedGroup);
                $this->assertYearIsOpen($academicYear);
                $lockedUnit = AcademicUnit::query()
                    ->whereKey($academicUnit->getKey())
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedGroup->update(['academic_unit_id' => $lockedUnit->getKey()]);

                return $lockedGroup->fresh();
            }, attempts: 3);
        });
    }

    /**
     * Enroll a group in a period from its own academic year.
     */
    public function enrollInPeriod(
        Organization $organization,
        StudentGroup $studentGroup,
        AcademicPeriod $academicPeriod,
    ): StudentGroup {
        return $this->tenantContext->run($organization, function () use ($organization, $studentGroup, $academicPeriod): StudentGroup {
            return DB::transaction(function () use ($organization, $studentGroup, $academicPeriod): StudentGroup {
                $this->lockOrganization($organization);
                $lockedGroup = $this->lockGroup($organization, $studentGroup);
                $academicYear = $this->lockYear($organization, $lockedGroup);
                $lockedPeriod = AcademicPeriod::query()
                    ->whereKey($academicPeriod->getKey())
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->assertYearIsOpen($academicYear);

                if ($lockedPeriod->academic_year_id !== $academicYear->getKey()) {
                    throw ValidationException::withMessages([
                        'academic_period' => __('A student group can only participate in periods from its academic year.'),
                    ]);
                }

                if (! $this->activeDatesOverlapPeriod($lockedGroup, $lockedPeriod)) {
                    throw ValidationException::withMessages([
                        'academic_period' => __('The student group is not active during the selected period.'),
                    ]);
                }

                DB::table('student_group_periods')->insertOrIgnore([
                    'student_group_id' => $lockedGroup->getKey(),
                    'academic_period_id' => $lockedPeriod->getKey(),
                    'organization_id' => $organization->getKey(),
                ]);

                return $lockedGroup->fresh();
            }, attempts: 3);
        });
    }

    /**
     * Remove a group from one of its academic-year periods.
     */
    public function unenrollFromPeriod(
        Organization $organization,
        StudentGroup $studentGroup,
        AcademicPeriod $academicPeriod,
    ): StudentGroup {
        return $this->tenantContext->run($organization, function () use ($organization, $studentGroup, $academicPeriod): StudentGroup {
            return DB::transaction(function () use ($organization, $studentGroup, $academicPeriod): StudentGroup {
                $this->lockOrganization($organization);
                $lockedGroup = $this->lockGroup($organization, $studentGroup);
                $academicYear = $this->lockYear($organization, $lockedGroup);
                $lockedPeriod = AcademicPeriod::query()
                    ->whereKey($academicPeriod->getKey())
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->assertYearIsOpen($academicYear);

                if ($lockedPeriod->academic_year_id !== $academicYear->getKey()) {
                    throw ValidationException::withMessages([
                        'academic_period' => __('A student group can only participate in periods from its academic year.'),
                    ]);
                }

                DB::table('student_group_periods')
                    ->where('organization_id', $organization->getKey())
                    ->where('student_group_id', $lockedGroup->getKey())
                    ->where('academic_period_id', $lockedPeriod->getKey())
                    ->delete();

                return $lockedGroup->fresh();
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

    private function lockGroup(Organization $organization, StudentGroup $studentGroup): StudentGroup
    {
        return StudentGroup::query()
            ->whereKey($studentGroup->getKey())
            ->where('organization_id', $organization->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockYear(Organization $organization, StudentGroup $studentGroup): AcademicYear
    {
        return AcademicYear::query()
            ->whereKey($studentGroup->academic_year_id)
            ->where('organization_id', $organization->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertYearIsOpen(AcademicYear $academicYear): void
    {
        if ($academicYear->status === AcademicYearStatus::Closed) {
            throw ValidationException::withMessages([
                'academic_year' => __('Closed academic years cannot change student-group membership.'),
            ]);
        }
    }

    private function assertActiveDates(
        AcademicYear $academicYear,
        ?CarbonInterface $activeFrom,
        ?CarbonInterface $activeUntil,
    ): void {
        if ($activeFrom && $activeUntil && $activeFrom->greaterThan($activeUntil)) {
            throw ValidationException::withMessages([
                'active_dates' => __('A student group must end on or after its active start date.'),
            ]);
        }

        if (($activeFrom && $activeFrom->lt($academicYear->starts_on))
            || ($activeUntil && $activeUntil->gt($academicYear->ends_on))) {
            throw ValidationException::withMessages([
                'active_dates' => __('Student-group active dates must fit within the academic year.'),
            ]);
        }
    }

    private function activeDatesOverlapPeriod(StudentGroup $studentGroup, AcademicPeriod $academicPeriod): bool
    {
        return ($studentGroup->active_from === null || $studentGroup->active_from->lte($academicPeriod->ends_on))
            && ($studentGroup->active_until === null || $studentGroup->active_until->gte($academicPeriod->starts_on));
    }
}
