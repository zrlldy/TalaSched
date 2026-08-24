<?php

namespace App\Scheduling\Constraints;

use App\Enums\ConstraintSeverity;
use App\Models\ConstraintConfiguration;
use App\Scheduling\ConstraintResult;
use App\Scheduling\SchedulingContext;

class RoomCapacityConstraintHandler extends AbstractConstraintHandler
{
    public function code(): string
    {
        return 'room_capacity';
    }

    public function defaultSeverity(): ConstraintSeverity
    {
        return ConstraintSeverity::Hard;
    }

    public function evaluate(SchedulingContext $context, ?ConstraintConfiguration $configuration): ConstraintResult
    {
        $issues = [];
        $enrollment = $context->offeringComponent->offering->expected_enrollment;

        foreach ($context->rooms as $room) {
            if ($room->capacity === null || $room->capacity >= $enrollment) {
                continue;
            }

            $issues[] = $this->issue(
                $this->code(),
                ConstraintSeverity::Hard,
                'resources',
                'The room capacity is below the offering enrollment.',
                $context->resource($room->scheduling_resource_id),
            );
        }

        return new ConstraintResult($issues);
    }
}
