<?php

namespace App\Academic;

use App\Audit\AuditLogger;
use App\Enums\AcademicPeriodKind;
use App\Enums\AcademicYearStatus;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcademicYearLifecycleService
{
    public function __construct(
        private TenantContext $tenantContext,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * Create a draft academic year for an organization.
     */
    public function createYear(
        Organization $organization,
        string $name,
        CarbonInterface $startsOn,
        CarbonInterface $endsOn,
        ?User $actor = null,
    ): AcademicYear {
        return $this->tenantContext->run($organization, function () use ($organization, $name, $startsOn, $endsOn, $actor): AcademicYear {
            return DB::transaction(function () use ($organization, $name, $startsOn, $endsOn, $actor): AcademicYear {
                $this->lockOrganization($organization);

                $academicYear = AcademicYear::query()->create([
                    'organization_id' => $organization->getKey(),
                    'name' => $name,
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                    'status' => AcademicYearStatus::Draft,
                ]);

                $this->auditLogger->record(
                    action: 'academic_year.created',
                    organization: $organization,
                    actor: $actor,
                    subject: $academicYear,
                    after: $this->yearSnapshot($academicYear),
                );

                return $academicYear;
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
        ?User $actor = null,
    ): AcademicPeriod {
        return $this->tenantContext->run($organization, function () use ($organization, $academicYear, $name, $kind, $sequence, $startsOn, $endsOn, $actor): AcademicPeriod {
            return DB::transaction(function () use ($organization, $academicYear, $name, $kind, $sequence, $startsOn, $endsOn, $actor): AcademicPeriod {
                $this->lockOrganization($organization);
                $lockedYear = $this->lockYear($organization, $academicYear);
                $this->assertDraft($lockedYear);
                $this->assertPeriodDetails($lockedYear, $sequence, $startsOn, $endsOn);
                $this->assertNoOverlappingPeriod($organization, $lockedYear, $startsOn, $endsOn);

                $academicPeriod = AcademicPeriod::query()->create([
                    'organization_id' => $organization->getKey(),
                    'academic_year_id' => $lockedYear->getKey(),
                    'name' => $name,
                    'kind' => $kind,
                    'sequence' => $sequence,
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                ]);

                $this->auditLogger->record(
                    action: 'academic_period.created',
                    organization: $organization,
                    actor: $actor,
                    subject: $academicPeriod,
                    after: $this->periodSnapshot($academicPeriod),
                );

                return $academicPeriod;
            }, attempts: 3);
        });
    }

    /**
     * Activate a draft year once its configurable periods are complete.
     */
    public function activate(Organization $organization, AcademicYear $academicYear, ?User $actor = null): AcademicYear
    {
        return $this->tenantContext->run($organization, function () use ($organization, $academicYear, $actor): AcademicYear {
            return DB::transaction(function () use ($organization, $academicYear, $actor): AcademicYear {
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

                $before = $this->yearSnapshot($lockedYear);
                $lockedYear->update(['status' => AcademicYearStatus::Active]);
                $lockedYear->refresh();

                $this->auditLogger->record(
                    action: 'academic_year.activated',
                    organization: $organization,
                    actor: $actor,
                    subject: $lockedYear,
                    before: $before,
                    after: $this->yearSnapshot($lockedYear),
                );

                return $lockedYear;
            }, attempts: 3);
        });
    }

    /**
     * Close an active academic year without changing its historical periods.
     */
    public function close(Organization $organization, AcademicYear $academicYear, ?User $actor = null): AcademicYear
    {
        return $this->tenantContext->run($organization, function () use ($organization, $academicYear, $actor): AcademicYear {
            return DB::transaction(function () use ($organization, $academicYear, $actor): AcademicYear {
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

                $before = $this->yearSnapshot($lockedYear);
                $lockedYear->update(['status' => AcademicYearStatus::Closed]);
                $lockedYear->refresh();

                $this->auditLogger->record(
                    action: 'academic_year.closed',
                    organization: $organization,
                    actor: $actor,
                    subject: $lockedYear,
                    before: $before,
                    after: $this->yearSnapshot($lockedYear),
                );

                return $lockedYear;
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

    private function assertPeriodDetails(AcademicYear $academicYear, int $sequence, CarbonInterface $startsOn, CarbonInterface $endsOn): void
    {
        $errors = [];

        if ($startsOn->lt($academicYear->starts_on) || $startsOn->gt($academicYear->ends_on)) {
            $errors['starts_on'] = __('The period start date must fall within the academic year.');
        }

        if ($endsOn->lt($academicYear->starts_on) || $endsOn->gt($academicYear->ends_on)) {
            $errors['ends_on'] = __('The period end date must fall within the academic year.');
        } elseif ($endsOn->lt($startsOn)) {
            $errors['ends_on'] = __('The period must end on or after its start date.');
        }

        if ($sequence < 1) {
            $errors['sequence'] = __('Period sequences must start at one.');
        } elseif (AcademicPeriod::query()
            ->where('organization_id', $academicYear->organization_id)
            ->where('academic_year_id', $academicYear->getKey())
            ->where('sequence', $sequence)
            ->exists()) {
            $errors['sequence'] = __('This sequence is already used in the academic year. Choose an unused sequence number.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
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

    /**
     * @return array{id: string, name: string, starts_on: string, ends_on: string, status: string}
     */
    private function yearSnapshot(AcademicYear $academicYear): array
    {
        return [
            'id' => $academicYear->public_id,
            'name' => $academicYear->name,
            'starts_on' => $academicYear->starts_on->toDateString(),
            'ends_on' => $academicYear->ends_on->toDateString(),
            'status' => $academicYear->status->value,
        ];
    }

    /**
     * @return array{id: string, name: string, kind: string, sequence: int, starts_on: string, ends_on: string}
     */
    private function periodSnapshot(AcademicPeriod $academicPeriod): array
    {
        return [
            'id' => $academicPeriod->public_id,
            'name' => $academicPeriod->name,
            'kind' => $academicPeriod->kind->value,
            'sequence' => $academicPeriod->sequence,
            'starts_on' => $academicPeriod->starts_on->toDateString(),
            'ends_on' => $academicPeriod->ends_on->toDateString(),
        ];
    }
}
