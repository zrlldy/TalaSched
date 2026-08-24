<?php

namespace App\Scheduling;

use App\Enums\TimetableVersionStatus;
use App\Models\TimetableVersion;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Gate;

class RollbackTimetableVersion
{
    public function __construct(private CloneTimetableVersion $clone) {}

    public function handle(TimetableVersion $source, User $actor): TimetableVersion
    {
        Gate::forUser($actor)->authorize('rollback', $source);

        if (! in_array($source->status, [TimetableVersionStatus::Published, TimetableVersionStatus::Superseded], true)) {
            throw new DomainException('Only published or superseded timetable versions may be rolled back.');
        }

        return $this->clone->handle($source, $actor, auditAction: 'timetable_version.rolled_back');
    }
}
