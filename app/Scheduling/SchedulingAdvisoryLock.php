<?php

namespace App\Scheduling;

use App\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class SchedulingAdvisoryLock
{
    /**
     * Acquire transaction-scoped locks in one deterministic order.
     *
     * @param  array<int, int>  $resourceIds
     */
    public function acquire(
        Organization $organization,
        int $timetableVersionId,
        array $resourceIds,
        ?CarbonInterface $date = null,
    ): void {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->lockNames($organization, $timetableVersionId, $resourceIds, $date) as $lockName) {
            DB::select(
                'select pg_advisory_xact_lock(hashtextextended(?, 0)) as advisory_lock',
                [$lockName],
            );
        }
    }

    /**
     * @param  array<int, int>  $resourceIds
     * @return list<string>
     */
    public function lockNames(
        Organization $organization,
        int $timetableVersionId,
        array $resourceIds,
        ?CarbonInterface $date = null,
    ): array {
        $resourceIds = array_values(array_unique(array_map('intval', $resourceIds)));
        sort($resourceIds, SORT_NUMERIC);

        $names = [
            'talasched:organization:'.$organization->getKey(),
            'talasched:organization:'.$organization->getKey().':timetable-version:'.$timetableVersionId,
        ];

        if ($date !== null) {
            $names[] = 'talasched:organization:'.$organization->getKey().':date:'.$date->toDateString();
        }

        return [
            ...$names,
            ...array_map(
                fn (int $resourceId): string => 'talasched:organization:'.$organization->getKey().':resource:'.$resourceId,
                $resourceIds,
            ),
        ];
    }
}
