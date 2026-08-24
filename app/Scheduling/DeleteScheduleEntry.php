<?php

namespace App\Scheduling;

use App\Audit\AuditLogger;
use App\Exceptions\ScheduleConflictException;
use App\Exceptions\StaleWriteException;
use App\Models\Organization;
use App\Models\ScheduleEntry;
use App\Models\TimetableVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteScheduleEntry
{
    public function __construct(
        private SchedulingAdvisoryLock $advisoryLock,
        private AuditLogger $auditLogger,
        private ScheduleEntryAuditSnapshot $auditSnapshot,
    ) {}

    public function handle(
        Organization $organization,
        ScheduleEntry $entry,
        int $expectedLockVersion,
        ?User $actor = null,
    ): void {
        $resourceIds = $entry->reservations()->pluck('scheduling_resource_id')->map(fn (mixed $id): int => (int) $id)->all();

        DB::transaction(function () use ($organization, $entry, $expectedLockVersion, $resourceIds, $actor): void {
            $this->advisoryLock->acquire($organization, $entry->timetable_version_id, $resourceIds);
            $lockedEntry = ScheduleEntry::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($entry->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedEntry->lock_version !== $expectedLockVersion) {
                throw new StaleWriteException([
                    'id' => $lockedEntry->public_id,
                    'lock_version' => (int) $lockedEntry->lock_version,
                ]);
            }

            $before = $this->auditSnapshot->entry($lockedEntry);

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

            $lockedEntry->delete();

            $this->auditLogger->record(
                action: 'scheduling.entry_deleted',
                organization: $organization,
                actor: $actor,
                subject: $lockedEntry,
                before: $before,
            );
        }, attempts: 3);
    }
}
