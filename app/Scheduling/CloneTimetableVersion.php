<?php

namespace App\Scheduling;

use App\Enums\TimetableVersionStatus;
use App\Models\TimetableVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CloneTimetableVersion
{
    public function handle(TimetableVersion $source, User $actor): TimetableVersion
    {
        return DB::transaction(function () use ($source, $actor): TimetableVersion {
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

            $source->entries()->with(['resources', 'reservations'])->orderBy('id')->each(function ($entry) use ($target): void {
                $clone = $entry->replicate(['public_id', 'timetable_version_id', 'created_at', 'updated_at']);
                $clone->public_id = (string) Str::uuid();
                $clone->timetable_version_id = $target->id;
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
            });

            return $target->load('entries.resources');
        }, attempts: 3);
    }
}
