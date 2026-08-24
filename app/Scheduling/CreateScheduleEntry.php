<?php

namespace App\Scheduling;

use App\Audit\AuditLogger;
use App\Exceptions\ScheduleConflictException;
use App\Models\Organization;
use App\Models\ScheduleEntry;
use App\Models\TimetableVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateScheduleEntry
{
    public function __construct(
        private ValidateScheduleEntry $validator,
        private SchedulingAdvisoryLock $advisoryLock,
        private ScheduleDatabaseConflictTranslator $databaseConflictTranslator,
        private AuditLogger $auditLogger,
        private ScheduleEntryAuditSnapshot $auditSnapshot,
    ) {}

    public function handle(
        Organization $organization,
        ScheduleEntryData $data,
        ?User $actor = null,
    ): ScheduleEntry {
        try {
            return DB::transaction(function () use ($organization, $data, $actor): ScheduleEntry {
                $this->advisoryLock->acquire(
                    $organization,
                    $data->timetableVersionId,
                    array_column($data->resources, 'resource_id'),
                );
                TimetableVersion::query()->whereKey($data->timetableVersionId)->lockForUpdate()->firstOrFail();

                $issues = $this->validator->handle($organization, $data);

                $hardIssues = array_values(array_filter(
                    $issues,
                    fn (array $issue): bool => ($issue['severity'] ?? 'hard') === 'hard',
                ));

                if ($hardIssues !== []) {
                    throw new ScheduleConflictException($hardIssues);
                }

                $entry = ScheduleEntry::create([
                    'organization_id' => $organization->id,
                    'timetable_version_id' => $data->timetableVersionId,
                    'offering_component_id' => $data->offeringComponentId,
                    'logical_id' => (string) Str::uuid(),
                    'weekday' => $data->weekday,
                    'starts_at_minute' => $data->startsAtMinute,
                    'ends_at_minute' => $data->endsAtMinute,
                    'delivery_mode' => $data->deliveryMode,
                    'notes' => $data->notes,
                ]);

                foreach ($data->resources as $assignment) {
                    $entry->resources()->create([
                        'organization_id' => $organization->id,
                        'scheduling_resource_id' => $assignment['resource_id'],
                        'role' => $assignment['role'],
                    ]);

                    $entry->reservations()->create([
                        'organization_id' => $organization->id,
                        'timetable_version_id' => $data->timetableVersionId,
                        'scheduling_resource_id' => $assignment['resource_id'],
                        'weekday' => $data->weekday,
                        'starts_at_minute' => $data->startsAtMinute,
                        'ends_at_minute' => $data->endsAtMinute,
                        'is_active' => true,
                    ]);
                }

                $entry = $entry->load(['version', 'offeringComponent', 'resources.resource']);

                $this->auditLogger->record(
                    action: 'scheduling.entry_created',
                    organization: $organization,
                    actor: $actor,
                    subject: $entry,
                    after: $this->auditSnapshot->entry($entry),
                );

                return $entry;
            }, attempts: 3);
        } catch (QueryException $exception) {
            $issues = $this->databaseConflictTranslator->translate($exception, $organization, $data);

            if ($issues !== null) {
                throw new ScheduleConflictException($issues);
            }

            throw $exception;
        }
    }
}
