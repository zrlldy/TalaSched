<?php

namespace App\Scheduling;

final readonly class TimetableViewData
{
    /**
     * @param  array<string, mixed>  $context
     * @param  list<array<string, mixed>>  $entries
     */
    public function __construct(
        public string $scope,
        public ?string $date,
        public array $context,
        public array $entries,
    ) {}

    /** @return array{type: string, attributes: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'type' => 'timetable_view',
            'attributes' => [
                'scope' => $this->scope,
                'date' => $this->date,
                'context' => $this->context,
                'entries' => $this->entries,
            ],
        ];
    }
}
