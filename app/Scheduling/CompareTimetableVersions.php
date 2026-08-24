<?php

namespace App\Scheduling;

use App\Models\Organization;
use App\Models\ScheduleEntry;
use App\Models\ScheduleEntryResource;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class CompareTimetableVersions
{
    public function handle(
        Organization $organization,
        Timetable $timetable,
        TimetableVersion $fromVersion,
        TimetableVersion $toVersion,
    ): TimetableVersionComparisonData {
        if ($fromVersion->organization_id !== $organization->getKey()
            || $toVersion->organization_id !== $organization->getKey()
            || $fromVersion->timetable_id !== $timetable->getKey()
            || $toVersion->timetable_id !== $timetable->getKey()) {
            throw ValidationException::withMessages([
                'version_id' => 'Both timetable versions must belong to the selected timetable.',
            ]);
        }

        $fromEntries = $this->entries($fromVersion);
        $toEntries = $this->entries($toVersion);
        $logicalIds = $fromEntries->keys()
            ->merge($toEntries->keys())
            ->unique()
            ->sort(SORT_STRING)
            ->values();
        $changes = [];

        foreach ($logicalIds as $logicalId) {
            $fromEntry = $fromEntries->get($logicalId);
            $toEntry = $toEntries->get($logicalId);

            if (! $fromEntry instanceof ScheduleEntry) {
                $changes[] = [
                    'logical_id' => $logicalId,
                    'kind' => 'added',
                    'change_types' => ['added'],
                    'before' => null,
                    'after' => $this->entryData($toEntry),
                ];

                continue;
            }

            if (! $toEntry instanceof ScheduleEntry) {
                $changes[] = [
                    'logical_id' => $logicalId,
                    'kind' => 'removed',
                    'change_types' => ['removed'],
                    'before' => $this->entryData($fromEntry),
                    'after' => null,
                ];

                continue;
            }

            $fromData = $this->entryData($fromEntry);
            $toData = $this->entryData($toEntry);
            $changeTypes = $this->changeTypes($fromData, $toData);

            if ($changeTypes === []) {
                continue;
            }

            $changes[] = [
                'logical_id' => $logicalId,
                'kind' => count($changeTypes) === 1 ? $changeTypes[0] : 'changed',
                'change_types' => $changeTypes,
                'before' => $fromData,
                'after' => $toData,
            ];
        }

        return new TimetableVersionComparisonData($fromVersion, $toVersion, $changes);
    }

    /** @return Collection<int, ScheduleEntry> */
    private function entries(TimetableVersion $version): Collection
    {
        return $version->entries()
            ->with(['offeringComponent', 'resources.resource'])
            ->orderBy('logical_id')
            ->get()
            ->keyBy('logical_id');
    }

    /** @return array<string, mixed> */
    private function entryData(ScheduleEntry $entry): array
    {
        return [
            'id' => $entry->public_id,
            'logical_id' => $entry->logical_id,
            'offering_component_id' => $entry->offeringComponent->public_id,
            'weekday' => (int) $entry->weekday,
            'starts_at_minute' => (int) $entry->starts_at_minute,
            'ends_at_minute' => (int) $entry->ends_at_minute,
            'delivery_mode' => (string) $entry->delivery_mode,
            'notes' => $entry->notes,
            'resources' => $entry->resources
                ->map(fn (ScheduleEntryResource $assignment): array => [
                    'resource_id' => $assignment->resource->public_id,
                    'role' => $assignment->role->value,
                ])
                ->sortBy(fn (array $resource): string => $resource['resource_id'].':'.$resource['role'])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return list<string>
     */
    private function changeTypes(array $from, array $to): array
    {
        $types = [];

        if ([$from['weekday'], $from['starts_at_minute'], $from['ends_at_minute']]
            !== [$to['weekday'], $to['starts_at_minute'], $to['ends_at_minute']]) {
            $types[] = 'moved';
        }

        if ($from['resources'] !== $to['resources']) {
            $types[] = 'reassigned';
        }

        if ([$from['offering_component_id'], $from['delivery_mode'], $from['notes']]
            !== [$to['offering_component_id'], $to['delivery_mode'], $to['notes']]) {
            $types[] = 'changed';
        }

        return $types;
    }
}
