<?php

namespace App\Scheduling\Constraints;

use App\Enums\ConstraintSeverity;
use App\Models\ConstraintConfiguration;
use App\Models\ScheduleEntry;
use App\Scheduling\ConstraintResult;
use App\Scheduling\SchedulingContext;

class OfferingFulfillmentConstraintHandler extends AbstractConstraintHandler
{
    public function code(): string
    {
        return 'offering_fulfillment';
    }

    public function defaultSeverity(): ConstraintSeverity
    {
        return ConstraintSeverity::Hard;
    }

    public function evaluate(SchedulingContext $context, ?ConstraintConfiguration $configuration): ConstraintResult
    {
        $component = $context->offeringComponent;
        $duration = $context->data->endsAtMinute - $context->data->startsAtMinute;
        $scheduledMinutes = $context->existingEntries->sum(
            fn (ScheduleEntry $entry): int => $entry->ends_at_minute - $entry->starts_at_minute,
        );
        $issues = [];

        if ($duration !== $component->duration_minutes) {
            $issues[] = $this->issue(
                'offering_duration_mismatch',
                ConstraintSeverity::Hard,
                'ends_at_minute',
                'The scheduled duration must match the offering component duration.',
                details: [
                    'expected_duration_minutes' => $component->duration_minutes,
                    'actual_duration_minutes' => $duration,
                ],
            );
        }

        if ($context->existingEntries->count() >= $component->sessions_per_week) {
            $issues[] = $this->issue(
                'offering_session_limit',
                ConstraintSeverity::Hard,
                'offering_component_id',
                'The offering component already has all required weekly sessions scheduled.',
                details: [
                    'sessions_per_week' => $component->sessions_per_week,
                    'scheduled_sessions' => $context->existingEntries->count(),
                ],
            );
        }

        if ($scheduledMinutes + $duration > $component->weekly_minutes) {
            $issues[] = $this->issue(
                'offering_weekly_minutes',
                ConstraintSeverity::Hard,
                'offering_component_id',
                'The scheduled sessions would exceed the offering component weekly minutes.',
                details: [
                    'weekly_minutes' => $component->weekly_minutes,
                    'scheduled_minutes' => $scheduledMinutes + $duration,
                ],
            );
        }

        return new ConstraintResult($issues);
    }
}
