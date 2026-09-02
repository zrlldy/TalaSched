<?php

namespace App\Scheduling\Data;

final readonly class SchedulingProblem
{
    /**
     * @param  list<array{id: string, offering_component_id: string, duration_minutes: int, delivery_mode: string, required_resources: list<array{resource_id: string, role: string}>, candidate_resources: array<string, list<string>>, allowed_weekdays: list<int>}>  $requirements
     * @param  list<array{id: string, offering_component_id: string, weekday: int, starts_at_minute: int, ends_at_minute: int, resources: list<array{resource_id: string, role: string}>}>  $fixedEntries
     * @param  list<array{code: string, severity: string, priority: int, weight: float, configuration: array<string, mixed>}>  $constraints
     */
    public function __construct(
        public string $organizationPublicId,
        public string $timetablePublicId,
        public string $sourceVersionPublicId,
        public string $timezone,
        public int $granularityMinutes,
        public array $requirements,
        public array $fixedEntries,
        public array $constraints,
        public ?int $seed = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'organization_id' => $this->organizationPublicId,
            'timetable_id' => $this->timetablePublicId,
            'source_version_id' => $this->sourceVersionPublicId,
            'timezone' => $this->timezone,
            'granularity_minutes' => $this->granularityMinutes,
            'requirements' => $this->requirements,
            'fixed_entries' => $this->fixedEntries,
            'constraints' => $this->constraints,
            'seed' => $this->seed,
        ];
    }
}
