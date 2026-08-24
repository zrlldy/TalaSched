<?php

namespace App\Scheduling;

use App\Models\TimetableVersion;

final readonly class TimetableVersionComparisonData
{
    /**
     * @param  list<array<string, mixed>>  $changes
     */
    public function __construct(
        public TimetableVersion $fromVersion,
        public TimetableVersion $toVersion,
        public array $changes,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $summary = [
            'added' => 0,
            'removed' => 0,
            'moved' => 0,
            'reassigned' => 0,
            'changed' => 0,
            'total' => count($this->changes),
        ];

        foreach ($this->changes as $change) {
            foreach ($change['change_types'] as $changeType) {
                if (array_key_exists($changeType, $summary)) {
                    $summary[$changeType]++;
                }
            }
        }

        return [
            'id' => $this->fromVersion->public_id.'..'.$this->toVersion->public_id,
            'type' => 'timetable_version_comparison',
            'attributes' => [
                'from_version' => $this->versionData($this->fromVersion),
                'to_version' => $this->versionData($this->toVersion),
                'summary' => $summary,
                'changes' => $this->changes,
            ],
        ];
    }

    /** @return array{id: string, number: int, status: string} */
    private function versionData(TimetableVersion $version): array
    {
        return [
            'id' => $version->public_id,
            'number' => (int) $version->version_number,
            'status' => $version->status->value,
        ];
    }
}
