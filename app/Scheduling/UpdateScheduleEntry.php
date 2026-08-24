<?php

namespace App\Scheduling;

use App\Audit\AuditLogger;
use App\Exceptions\ScheduleConflictException;
use App\Exceptions\StaleWriteException;
use App\Models\Organization;
use App\Models\ScheduleEntry;
use App\Models\TimetableVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class UpdateScheduleEntry
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
        ScheduleEntry $entry,
        ScheduleEntryData $data,
        int $expectedLockVersion,
        ?User $actor = null,
    ): ScheduleEntry {
        $resourceIds = $entry->reservations()->pluck('scheduling_resource_id')->map(fn (mixed $id): int => (int) $id)->all();
        $resourceIds = [...$resourceIds, ...array_column($data->resources, 'resource_id')];

        try {
            return DB::transaction(function () use ($organization, $entry, $data, $expectedLockVersion, $resourceIds, $actor): ScheduleEntry {
                $this->advisoryLock->acquire($organization, $data->timetableVersionId, $resourceIds);
                $lockedEntry = ScheduleEntry::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKey($entry->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $lockedEntry->lock_version !== $expectedLockVersion) {
                    throw new StaleWriteException($this->current($lockedEntry));
                }

                $before = $this->auditSnapshot->entry($lockedEntry);

                if ($lockedEntry->timetable_version_id !== $data->timetableVersionId
                    || $lockedEntry->offering_component_id !== $data->offeringComponentId) {
                    throw new ScheduleConflictException([[
                        'code' => 'schedule_entry_identity_changed',
                        'severity' => 'hard',
                        'field' => 'schedule_entry_id',
                        'rule_code' => 'schedule_entry_identity_changed',
                        'message' => 'The timetable version and offering component cannot change during an entry update.',
                        'details' => [],
                    ]]);
                }

                $version = TimetableVersion::query()
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

                $issues = $this->validator->handle($organization, $data);
                $hardIssues = array_values(array_filter(
                    $issues,
                    fn (array $issue): bool => ($issue['severity'] ?? 'hard') === 'hard',
                ));

                if ($hardIssues !== []) {
                    throw new ScheduleConflictException($hardIssues);
                }

                $updated = ScheduleEntry::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereKey($lockedEntry->getKey())
                    ->where('lock_version', $expectedLockVersion)
                    ->update([
                        'weekday' => $data->weekday,
                        'starts_at_minute' => $data->startsAtMinute,
                        'ends_at_minute' => $data->endsAtMinute,
                        'delivery_mode' => $data->deliveryMode,
                        'notes' => $data->notes,
                        'lock_version' => $expectedLockVersion + 1,
                        'updated_at' => now(),
                    ]);

                if ($updated !== 1) {
                    $current = ScheduleEntry::query()
                        ->where('organization_id', $organization->getKey())
                        ->whereKey($lockedEntry->getKey())
                        ->firstOrFail();

                    throw new StaleWriteException($this->current($current));
                }

                $lockedEntry->resources()->delete();
                $lockedEntry->reservations()->delete();
                $this->createProjections($lockedEntry, $organization, $data);

                $updatedEntry = $lockedEntry->refresh()->load(['version', 'offeringComponent', 'resources.resource']);

                $this->auditLogger->record(
                    action: 'scheduling.entry_updated',
                    organization: $organization,
                    actor: $actor,
                    subject: $updatedEntry,
                    before: $before,
                    after: $this->auditSnapshot->entry($updatedEntry),
                );

                return $updatedEntry;
            }, attempts: 3);
        } catch (QueryException $exception) {
            $issues = $this->databaseConflictTranslator->translate($exception, $organization, $data);

            if ($issues !== null) {
                throw new ScheduleConflictException($issues);
            }

            throw $exception;
        }
    }

    /** @return array{id: string, lock_version: int} */
    private function current(ScheduleEntry $entry): array
    {
        return [
            'id' => $entry->public_id,
            'lock_version' => (int) $entry->lock_version,
        ];
    }

    private function createProjections(
        ScheduleEntry $entry,
        Organization $organization,
        ScheduleEntryData $data,
    ): void {
        foreach ($data->resources as $assignment) {
            $entry->resources()->create([
                'organization_id' => $organization->getKey(),
                'scheduling_resource_id' => $assignment['resource_id'],
                'role' => $assignment['role'],
            ]);
            $entry->reservations()->create([
                'organization_id' => $organization->getKey(),
                'timetable_version_id' => $data->timetableVersionId,
                'scheduling_resource_id' => $assignment['resource_id'],
                'weekday' => $data->weekday,
                'starts_at_minute' => $data->startsAtMinute,
                'ends_at_minute' => $data->endsAtMinute,
                'is_active' => true,
            ]);
        }
    }
}
