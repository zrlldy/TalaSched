<?php

namespace App\Scheduling\Data;

final readonly class SchedulingSolution
{
    /**
     * @param  list<array{requirement_id: string, weekday: int, starts_at_minute: int, ends_at_minute: int, resources: list<array{resource_id: string, role: string}>}>  $entries
     */
    public function __construct(
        public array $entries,
        public float $score,
    ) {}

    /**
     * @return array{entries: list<array{requirement_id: string, weekday: int, starts_at_minute: int, ends_at_minute: int, resources: list<array{resource_id: string, role: string}>}>, score: float}
     */
    public function toArray(): array
    {
        return [
            'entries' => $this->entries,
            'score' => $this->score,
        ];
    }
}
