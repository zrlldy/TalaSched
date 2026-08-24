<?php

namespace App\Scheduling;

use App\Audit\AuditLogger;
use App\Enums\TimetableVersionStatus;
use App\Models\Organization;
use App\Models\ScheduleEntry;
use App\Models\TimetableVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;

class CloneTimetableVersion
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function handle(
        TimetableVersion $source,
        User $actor,
        string $auditAction = 'timetable_version.cloned',
    ): TimetableVersion {
        Gate::forUser($actor)->authorize('clone', $source);

        return DB::transaction(function () use ($source, $actor, $auditAction): TimetableVersion {
            $source->load('timetable');
            $source->timetable()->lockForUpdate()->firstOrFail();

            $nextVersion = (int) TimetableVersion::query()
                ->where('timetable_id', $source->timetable_id)
                ->max('version_number') + 1;

            $target = TimetableVersion::create([
                'organization_id' => $source->organization_id,
                'timetable_id' => $source->timetable_id,
                'based_on_version_id' => $source->id,
                'version_number' => $nextVersion,
                'status' => TimetableVersionStatus::Draft,
                'created_by' => $actor->id,
            ]);

            $source->entries()
                ->with(['resources', 'reservations', 'exceptions.resources'])
                ->orderBy('id')
                ->each(function (ScheduleEntry $entry) use ($target): void {
                    $clone = $entry->replicate(['public_id', 'timetable_version_id', 'created_at', 'updated_at']);
                    $clone->public_id = (string) Str::uuid();
                    $clone->setAttribute('timetable_version_id', $target->getKey());
                    $clone->lock_version = 1;
                    $clone->save();

                    foreach ($entry->resources as $resource) {
                        $clone->resources()->create($resource->only(['organization_id', 'scheduling_resource_id', 'role']));
                    }

                    foreach ($entry->reservations as $reservation) {
                        $clone->reservations()->create($reservation->only([
                            'organization_id', 'scheduling_resource_id', 'weekday', 'starts_at_minute', 'ends_at_minute', 'is_active',
                        ]) + ['timetable_version_id' => $target->id]);
                    }

                    $this->cloneExceptions($entry, $clone);
                });

            $target = $target->load(['entries.resources', 'entries.exceptions.resources']);
            $organization = Organization::query()->findOrFail($target->organization_id);

            $this->auditLogger->record(
                action: $auditAction,
                organization: $organization,
                actor: $actor,
                subject: $target,
                after: [
                    'id' => $target->public_id,
                    'source_version_id' => $source->public_id,
                    'version_number' => (int) $target->version_number,
                    'status' => $target->status->value,
                    'entry_count' => $target->entries->count(),
                ],
            );

            return $target;
        }, attempts: 3);
    }

    private function cloneExceptions(ScheduleEntry $sourceEntry, ScheduleEntry $targetEntry): void
    {
        foreach ($sourceEntry->exceptions as $exception) {
            $clone = $exception->replicate(['public_id', 'schedule_entry_id', 'created_at', 'updated_at']);
            $clone->public_id = (string) Str::uuid();
            $clone->organization_id = $targetEntry->organization_id;
            $clone->schedule_entry_id = $targetEntry->getKey();
            $clone->save();

            $resources = [];

            foreach ($exception->resources as $resource) {
                $pivot = $resource->getRelationValue('pivot');

                if (! $pivot instanceof Pivot) {
                    throw new LogicException('Schedule exception resources must include their pivot data.');
                }

                $role = $pivot->getAttribute('role');

                if (! is_string($role)) {
                    throw new LogicException('Schedule exception resource pivots must include a role.');
                }

                $resources[(int) $resource->getKey()] = [
                    'organization_id' => $targetEntry->organization_id,
                    'role' => $role,
                ];
            }

            $clone->resources()->sync($resources);
        }
    }
}
