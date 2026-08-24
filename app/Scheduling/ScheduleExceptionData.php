<?php

namespace App\Scheduling;

use App\Enums\ScheduleExceptionAction;
use Carbon\CarbonImmutable;

final readonly class ScheduleExceptionData
{
    /**
     * @param  array<int, array{resource_id: int, role: string}>  $resources
     */
    public function __construct(
        public CarbonImmutable $date,
        public ScheduleExceptionAction $action,
        public ?int $startsAtMinute,
        public ?int $endsAtMinute,
        public array $resources,
        public ?string $reason = null,
    ) {}
}
