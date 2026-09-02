<?php

namespace App\Scheduling\Data;

use App\Enums\GenerationStatus;
use App\Scheduling\ConstraintIssue;

final readonly class GenerationResult
{
    /**
     * @param  list<ConstraintIssue>  $explanations
     */
    public function __construct(
        public GenerationStatus $status,
        public ?SchedulingSolution $solution = null,
        public array $explanations = [],
    ) {}

    /**
     * @return array{status: string, solution: array{entries: list<array{requirement_id: string, weekday: int, starts_at_minute: int, ends_at_minute: int, resources: list<array{resource_id: string, role: string}>}>, score: float}|null, explanations: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'solution' => $this->solution?->toArray(),
            'explanations' => array_map(
                fn (ConstraintIssue $issue): array => $issue->toArray(),
                $this->explanations,
            ),
        ];
    }
}
