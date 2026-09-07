<?php

namespace App\Scheduling;

use App\Audit\AuditLogger;
use App\Enums\AcademicYearStatus;
use App\Enums\CapabilityKey;
use App\Enums\TimetableVersionStatus;
use App\Models\AcademicPeriod;
use App\Models\Organization;
use App\Models\Timetable;
use App\Models\User;
use App\Subscriptions\CapabilityGuard;
use App\Subscriptions\UsageService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateTimetable
{
    public function __construct(
        private TenantContext $tenantContext,
        private UsageService $usage,
        private CapabilityGuard $capabilities,
        private AuditLogger $auditLogger,
    ) {}

    public function handle(Organization $organization, User $actor, string $periodId, string $name): Timetable
    {
        return $this->tenantContext->run($organization, function () use ($organization, $actor, $periodId, $name): Timetable {
            return DB::transaction(function () use ($organization, $actor, $periodId, $name): Timetable {
                $organization = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('create', [Timetable::class, $organization]);

                $period = AcademicPeriod::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('public_id', $periodId)
                    ->with('academicYear')
                    ->firstOrFail();

                if ($period->academicYear->status === AcademicYearStatus::Closed) {
                    throw ValidationException::withMessages(['academic_period_id' => __('Choose a period in an open academic year.')]);
                }

                if ($period->timetables()->exists()) {
                    throw ValidationException::withMessages(['academic_period_id' => __('This period already has a timetable. Open it from the timetable list.')]);
                }

                if ($this->capabilities->limit($organization, CapabilityKey::MaxActiveTimetables) === null
                    || ! $this->capabilities->hasCapacity($organization, CapabilityKey::MaxActiveTimetables, $this->usage->current($organization, CapabilityKey::MaxActiveTimetables))) {
                    throw ValidationException::withMessages(['capacity' => __('Your timetable limit has been reached or is not configured. Review your plan and usage before creating another timetable.')]);
                }

                $this->usage->reserve($organization, CapabilityKey::MaxActiveTimetables);

                $timetable = Timetable::query()->create([
                    'organization_id' => $organization->getKey(),
                    'academic_year_id' => $period->academic_year_id,
                    'academic_period_id' => $period->getKey(),
                    'name' => $name,
                    'timezone' => $organization->timezone,
                    'scheduling_granularity' => $organization->scheduling_granularity,
                ]);

                $version = $timetable->versions()->create([
                    'organization_id' => $organization->getKey(),
                    'version_number' => 1,
                    'status' => TimetableVersionStatus::Draft,
                    'lock_version' => 1,
                    'created_by' => $actor->getKey(),
                ]);

                $this->auditLogger->record(
                    action: 'timetable.created', organization: $organization, actor: $actor, subject: $timetable,
                    after: ['name' => $name, 'academic_period_id' => $period->public_id, 'version_id' => $version->public_id],
                );

                return $timetable;
            }, attempts: 3);
        });
    }
}
