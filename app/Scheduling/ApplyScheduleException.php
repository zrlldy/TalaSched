<?php

namespace App\Scheduling;

use App\Audit\AuditLogger;
use App\Enums\ScheduleExceptionAction;
use App\Exceptions\ScheduleConflictException;
use App\Models\AcademicPeriod;
use App\Models\Organization;
use App\Models\ScheduleEntry;
use App\Models\ScheduleEntryException;
use App\Models\ScheduleEntryResource;
use App\Models\TimetableVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplyScheduleException
{
    public function __construct(
        private ValidateScheduleEntry $validator,
        private SchedulingAdvisoryLock $advisoryLock,
        private AuditLogger $auditLogger,
        private ScheduleEntryAuditSnapshot $auditSnapshot,
    ) {}

    public function handle(
        Organization $organization,
        ScheduleEntry $entry,
        ScheduleExceptionData $data,
        ?User $actor = null,
    ): ScheduleEntryException {
        $existingResourceIds = $entry->reservations()
            ->pluck('scheduling_resource_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $resourceIds = [...$existingResourceIds, ...array_column($data->resources, 'resource_id')];

        return DB::transaction(function () use ($organization, $entry, $data, $resourceIds, $actor): ScheduleEntryException {
            $this->advisoryLock->acquire(
                $organization,
                $entry->timetable_version_id,
                $resourceIds,
                $data->date,
            );
            $lockedEntry = ScheduleEntry::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($entry->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $version = TimetableVersion::query()
                ->with('timetable')
                ->where('organization_id', $organization->getKey())
                ->whereKey($lockedEntry->timetable_version_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $version->status->isEditable()) {
                throw new ScheduleConflictException([[
                    'code' => 'version_not_editable',
                    'severity' => 'hard',
                    'field' => 'timetable_version_id',
                    'rule_code' => 'version_not_editable',
                    'message' => 'Published or review-locked timetable versions cannot be edited.',
                    'details' => [],
                ]]);
            }

            $period = AcademicPeriod::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($version->timetable->academic_period_id)
                ->firstOrFail();
            $this->assertDateMatchesEntry($period, $lockedEntry, $data);

            $effectiveResources = $data->resources !== []
                ? $data->resources
                : $this->entryResources($lockedEntry);
            $startsAtMinute = $data->startsAtMinute ?? (int) $lockedEntry->starts_at_minute;
            $endsAtMinute = $data->endsAtMinute ?? (int) $lockedEntry->ends_at_minute;

            if ($data->action === ScheduleExceptionAction::Cancelled) {
                $startsAtMinute = null;
                $endsAtMinute = null;
                $effectiveResources = [];
            } else {
                [$startsAtMinute, $endsAtMinute] = $this->validatedTimeWindow(
                    $startsAtMinute,
                    $endsAtMinute,
                );

                if ($data->action === ScheduleExceptionAction::Replaced && $data->resources === []) {
                    throw ValidationException::withMessages([
                        'resources' => 'Replacement exceptions must provide replacement resources.',
                    ]);
                }

                $issues = $this->validator->handle($organization, new ScheduleEntryData(
                    timetableVersionId: (int) $lockedEntry->timetable_version_id,
                    offeringComponentId: (int) $lockedEntry->offering_component_id,
                    weekday: $data->date->dayOfWeekIso,
                    startsAtMinute: $startsAtMinute,
                    endsAtMinute: $endsAtMinute,
                    resources: $effectiveResources,
                    deliveryMode: (string) $lockedEntry->delivery_mode,
                    existingEntryId: (int) $lockedEntry->getKey(),
                    occurrenceDate: $data->date,
                ));
                $hardIssues = array_values(array_filter(
                    $issues,
                    fn (array $issue): bool => ($issue['severity'] ?? 'hard') === 'hard',
                ));

                if ($hardIssues !== []) {
                    throw new ScheduleConflictException($hardIssues);
                }
            }

            $exception = ScheduleEntryException::query()
                ->where('organization_id', $organization->getKey())
                ->where('schedule_entry_id', $lockedEntry->getKey())
                ->whereDate('date', $data->date->toDateString())
                ->lockForUpdate()
                ->first();

            if (! $exception instanceof ScheduleEntryException) {
                $exception = new ScheduleEntryException([
                    'organization_id' => $organization->getKey(),
                    'schedule_entry_id' => $lockedEntry->getKey(),
                    'date' => $data->date,
                ]);
            }

            $before = $exception->exists ? $this->auditSnapshot->exception($exception) : null;

            $exception->fill([
                'action' => $data->action,
                'starts_at_minute' => $startsAtMinute,
                'ends_at_minute' => $endsAtMinute,
                'reason' => $data->reason,
            ]);
            $exception->save();

            $exception->resources()->sync($this->pivotResources(
                $data->action === ScheduleExceptionAction::Replaced
                    || ($data->action === ScheduleExceptionAction::Rescheduled && $data->resources !== [])
                    ? $data->resources
                    : [],
                $organization,
            ));

            $exception = $exception->fresh(['entry', 'resources']);

            $this->auditLogger->record(
                action: 'scheduling.exception_applied',
                organization: $organization,
                actor: $actor,
                subject: $exception,
                before: $before,
                after: $this->auditSnapshot->exception($exception),
            );

            return $exception;
        }, attempts: 3);
    }

    private function assertDateMatchesEntry(
        AcademicPeriod $period,
        ScheduleEntry $entry,
        ScheduleExceptionData $data,
    ): void {
        if ($data->date->lt($period->starts_on) || $data->date->gt($period->ends_on)) {
            throw ValidationException::withMessages([
                'date' => 'Schedule exceptions must fall within the timetable academic period.',
            ]);
        }

        if ($data->date->dayOfWeekIso !== (int) $entry->weekday) {
            throw ValidationException::withMessages([
                'date' => 'Schedule exceptions must match the recurring entry weekday.',
            ]);
        }
    }

    /** @return array{int, int} */
    private function validatedTimeWindow(?int $startsAtMinute, ?int $endsAtMinute): array
    {
        if ($startsAtMinute === null || $endsAtMinute === null) {
            throw ValidationException::withMessages([
                'time' => 'Rescheduled and replacement exceptions require a positive time window.',
            ]);
        }

        if ($startsAtMinute < 0
            || $endsAtMinute > 1440
            || $startsAtMinute >= $endsAtMinute) {
            throw ValidationException::withMessages([
                'time' => 'Rescheduled and replacement exceptions require a positive time window.',
            ]);
        }

        return [$startsAtMinute, $endsAtMinute];
    }

    /** @return array<int, array{resource_id: int, role: string}> */
    private function entryResources(ScheduleEntry $entry): array
    {
        return $entry->resources()
            ->get()
            ->map(fn (ScheduleEntryResource $resource): array => [
                'resource_id' => (int) $resource->scheduling_resource_id,
                'role' => $resource->role->value,
            ])
            ->all();
    }

    /**
     * @param  array<int, array{resource_id: int, role: string}>  $resources
     * @return array<int, array{organization_id: int, role: string}>
     */
    private function pivotResources(array $resources, Organization $organization): array
    {
        $pivot = [];

        foreach ($resources as $resource) {
            $pivot[$resource['resource_id']] = [
                'organization_id' => $organization->getKey(),
                'role' => $resource['role'],
            ];
        }

        return $pivot;
    }
}
