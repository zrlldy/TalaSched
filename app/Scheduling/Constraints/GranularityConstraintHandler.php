<?php

namespace App\Scheduling\Constraints;

use App\Enums\ConstraintSeverity;
use App\Models\ConstraintConfiguration;
use App\Scheduling\ConstraintResult;
use App\Scheduling\SchedulingContext;

class GranularityConstraintHandler extends AbstractConstraintHandler
{
    public function code(): string
    {
        return 'invalid_granularity';
    }

    public function defaultSeverity(): ConstraintSeverity
    {
        return ConstraintSeverity::Hard;
    }

    public function evaluate(SchedulingContext $context, ?ConstraintConfiguration $configuration): ConstraintResult
    {
        $granularity = $context->version->timetable->scheduling_granularity;

        if ($context->data->startsAtMinute % $granularity === 0
            && $context->data->endsAtMinute % $granularity === 0) {
            return ConstraintResult::empty();
        }

        return ConstraintResult::from($this->issue(
            $this->code(),
            ConstraintSeverity::Hard,
            'starts_at_minute',
            'Start and end times must follow the timetable scheduling interval.',
        ));
    }
}
