<?php

namespace App\Scheduling;

use App\Models\Organization;
use App\Models\ScheduleEntryResource;
use App\Models\TimetableVersion;

class ValidateTimetableVersion
{
    public function __construct(private ValidateScheduleEntry $validator) {}

    /** @return list<array<string, mixed>> */
    public function hardIssues(Organization $organization, TimetableVersion $version): array
    {
        $issues = [];

        $entries = $version->entries()
            ->with('resources')
            ->orderBy('id')
            ->get();

        foreach ($entries as $entry) {
            $result = $this->validator->evaluate(
                $organization,
                new ScheduleEntryData(
                    timetableVersionId: (int) $entry->timetable_version_id,
                    offeringComponentId: (int) $entry->offering_component_id,
                    weekday: (int) $entry->weekday,
                    startsAtMinute: (int) $entry->starts_at_minute,
                    endsAtMinute: (int) $entry->ends_at_minute,
                    resources: $entry->resources
                        ->map(fn (ScheduleEntryResource $assignment): array => [
                            'resource_id' => (int) $assignment->scheduling_resource_id,
                            'role' => $assignment->role->value,
                        ])
                        ->all(),
                    deliveryMode: (string) $entry->delivery_mode,
                    notes: $entry->notes,
                    existingEntryId: (int) $entry->getKey(),
                ),
                checkVersionEditability: false,
            );

            foreach ($result->hardIssues()->toArray() as $issue) {
                $details = is_array($issue['details'] ?? null) ? $issue['details'] : [];
                $issue['details'] = [
                    ...$details,
                    'entry_id' => $entry->public_id,
                    'logical_id' => $entry->logical_id,
                ];
                $issues[] = $issue;
            }
        }

        return $issues;
    }
}
