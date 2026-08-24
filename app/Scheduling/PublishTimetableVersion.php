<?php

namespace App\Scheduling;

use App\Audit\AuditLogger;
use App\Enums\TimetableVersionStatus;
use App\Exceptions\ScheduleConflictException;
use App\Models\Organization;
use App\Models\TimetableVersion;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PublishTimetableVersion
{
    public function __construct(
        private ValidateTimetableVersion $versionValidator,
        private AuditLogger $auditLogger,
    ) {}

    public function handle(TimetableVersion $version, User $actor): TimetableVersion
    {
        Gate::forUser($actor)->authorize('publish', $version);

        return DB::transaction(function () use ($version, $actor): TimetableVersion {
            $version = TimetableVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            $version->timetable()->lockForUpdate()->firstOrFail();

            if ($version->status !== TimetableVersionStatus::Approved) {
                throw new DomainException('Only an approved timetable version may be published.');
            }

            $before = $this->versionSnapshot($version);

            $organization = Organization::query()->findOrFail($version->organization_id);
            $hardIssues = $this->versionValidator->hardIssues($organization, $version);

            if ($hardIssues !== []) {
                throw new ScheduleConflictException($hardIssues);
            }

            TimetableVersion::query()
                ->where('timetable_id', $version->timetable_id)
                ->where('status', TimetableVersionStatus::Published)
                ->update(['status' => TimetableVersionStatus::Superseded]);

            $version->update([
                'status' => TimetableVersionStatus::Published,
                'published_by' => $actor->id,
                'published_at' => now(),
            ]);

            $publishedVersion = $version->fresh();
            $organization = Organization::query()->findOrFail($publishedVersion->organization_id);

            $this->auditLogger->record(
                action: 'timetable_version.published',
                organization: $organization,
                actor: $actor,
                subject: $publishedVersion,
                before: $before,
                after: $this->versionSnapshot($publishedVersion),
            );

            return $publishedVersion;
        }, attempts: 3);
    }

    /** @return array{id: string, version_number: int, status: string} */
    private function versionSnapshot(TimetableVersion $version): array
    {
        return [
            'id' => $version->public_id,
            'version_number' => (int) $version->version_number,
            'status' => $version->status->value,
        ];
    }
}
