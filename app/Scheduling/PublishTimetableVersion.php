<?php

namespace App\Scheduling;

use App\Enums\TimetableVersionStatus;
use App\Models\TimetableVersion;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class PublishTimetableVersion
{
    public function handle(TimetableVersion $version, User $actor): TimetableVersion
    {
        return DB::transaction(function () use ($version, $actor): TimetableVersion {
            $version = TimetableVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            $version->timetable()->lockForUpdate()->firstOrFail();

            if ($version->status !== TimetableVersionStatus::Approved) {
                throw new DomainException('Only an approved timetable version may be published.');
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

            return $version->fresh();
        }, attempts: 3);
    }
}
