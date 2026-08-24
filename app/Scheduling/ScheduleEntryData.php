<?php

namespace App\Scheduling;

use Carbon\CarbonImmutable;

final readonly class ScheduleEntryData
{
    /**
     * @param  array<int, array{resource_id: int, role: string}>  $resources
     */
    public function __construct(
        public int $timetableVersionId,
        public int $offeringComponentId,
        public int $weekday,
        public int $startsAtMinute,
        public int $endsAtMinute,
        public array $resources,
        public string $deliveryMode = 'physical',
        public ?string $notes = null,
        public ?int $existingEntryId = null,
        public ?CarbonImmutable $occurrenceDate = null,
    ) {}
}
