<?php

namespace App\Scheduling;

use App\Models\Organization;
use App\Models\SchedulingResource;
use Illuminate\Database\QueryException;

class ScheduleDatabaseConflictTranslator
{
    /**
     * Translate PostgreSQL's final reservation guard into the public conflict contract.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function translate(
        QueryException $exception,
        Organization $organization,
        ScheduleEntryData $data,
    ): ?array {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverMessage = (string) ($exception->errorInfo[2] ?? '');
        $isExclusionViolation = $sqlState === '23P01';
        $isScheduleUniquenessViolation = $sqlState === '23505'
            && (
                str_contains($driverMessage, 'schedule_reservations')
                || str_contains($driverMessage, 'schedule_entry_resources')
            );

        if (! $isExclusionViolation && ! $isScheduleUniquenessViolation) {
            return null;
        }

        $resourceIds = collect($data->resources)
            ->pluck('resource_id')
            ->map(fn (mixed $resourceId): int => (int) $resourceId)
            ->unique()
            ->values();
        $resources = SchedulingResource::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('id', $resourceIds)
            ->get()
            ->keyBy('id');
        $constraint = $isExclusionViolation
            ? 'schedule_resource_no_overlap'
            : 'schedule_reservation_unique';
        $issues = [];

        foreach ($resourceIds as $resourceId) {
            $resource = $resources->get($resourceId);
            $issues[] = [
                'code' => 'resource_overlap',
                'severity' => 'hard',
                'field' => 'resources',
                'rule_code' => 'resource_overlap',
                'message' => 'The resource was booked concurrently by another schedule request.',
                'resource' => $resource === null ? null : [
                    'id' => $resource->public_id,
                    'name' => $resource->name,
                ],
                'conflicting_entry_id' => null,
                'details' => [
                    'source' => 'database',
                    'constraint' => $constraint,
                ],
            ];
        }

        return $issues;
    }
}
