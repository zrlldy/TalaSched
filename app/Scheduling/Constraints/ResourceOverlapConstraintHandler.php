<?php

namespace App\Scheduling\Constraints;

use App\Enums\ConstraintSeverity;
use App\Models\ConstraintConfiguration;
use App\Scheduling\ConstraintResult;
use App\Scheduling\SchedulingContext;

class ResourceOverlapConstraintHandler extends AbstractConstraintHandler
{
    public function code(): string
    {
        return 'resource_overlap';
    }

    public function defaultSeverity(): ConstraintSeverity
    {
        return ConstraintSeverity::Hard;
    }

    public function evaluate(SchedulingContext $context, ?ConstraintConfiguration $configuration): ConstraintResult
    {
        $issues = [];

        foreach ($context->reservations as $reservation) {
            if ($reservation->weekday !== $context->data->weekday
                || $reservation->starts_at_minute >= $context->data->endsAtMinute
                || $reservation->ends_at_minute <= $context->data->startsAtMinute) {
                continue;
            }

            $resource = $context->resource($reservation->scheduling_resource_id);
            $entryId = $reservation->scheduleEntry?->public_id;

            $issues[] = $this->issue(
                $this->code(),
                ConstraintSeverity::Hard,
                'resources',
                'The resource is already assigned during this time.',
                $resource,
                $entryId,
            );
        }

        return new ConstraintResult($issues);
    }
}
