<?php

namespace App\Scheduling\Constraints;

use App\Enums\ConstraintSeverity;
use App\Models\ConstraintConfiguration;
use App\Models\ScheduleReservation;
use App\Scheduling\ConstraintResult;
use App\Scheduling\SchedulingContext;

class FacultyLoadConstraintHandler extends AbstractConstraintHandler
{
    public function code(): string
    {
        return 'faculty_load';
    }

    public function defaultSeverity(): ConstraintSeverity
    {
        return ConstraintSeverity::Hard;
    }

    public function evaluate(SchedulingContext $context, ?ConstraintConfiguration $configuration): ConstraintResult
    {
        $duration = $context->data->endsAtMinute - $context->data->startsAtMinute;
        $issues = [];

        foreach ($context->facultyProfiles as $faculty) {
            $reservations = $context->reservations->where('scheduling_resource_id', $faculty->scheduling_resource_id);
            $dailyLoad = $reservations
                ->where('weekday', $context->data->weekday)
                ->sum(fn (ScheduleReservation $reservation): int => $reservation->ends_at_minute - $reservation->starts_at_minute);
            $weeklyLoad = $reservations
                ->sum(fn (ScheduleReservation $reservation): int => $reservation->ends_at_minute - $reservation->starts_at_minute);
            $resource = $context->resource($faculty->scheduling_resource_id);

            if ($faculty->maximum_daily_minutes !== null && $dailyLoad + $duration > $faculty->maximum_daily_minutes) {
                $issues[] = $this->issue(
                    'faculty_daily_load',
                    ConstraintSeverity::Hard,
                    'resources',
                    'The faculty member would exceed the maximum daily teaching load.',
                    $resource,
                );
            }

            if ($faculty->maximum_weekly_minutes !== null && $weeklyLoad + $duration > $faculty->maximum_weekly_minutes) {
                $issues[] = $this->issue(
                    'faculty_weekly_load',
                    ConstraintSeverity::Hard,
                    'resources',
                    'The faculty member would exceed the maximum weekly teaching load.',
                    $resource,
                );
            }
        }

        return new ConstraintResult($issues);
    }
}
