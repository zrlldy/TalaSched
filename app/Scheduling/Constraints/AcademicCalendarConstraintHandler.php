<?php

namespace App\Scheduling\Constraints;

use App\Enums\CalendarExceptionKind;
use App\Enums\ConstraintSeverity;
use App\Models\AcademicCalendar;
use App\Models\CalendarException;
use App\Models\ConstraintConfiguration;
use App\Scheduling\ConstraintResult;
use App\Scheduling\SchedulingContext;

class AcademicCalendarConstraintHandler extends AbstractConstraintHandler
{
    public function code(): string
    {
        return 'calendar_operating_hours';
    }

    public function defaultSeverity(): ConstraintSeverity
    {
        return ConstraintSeverity::Hard;
    }

    public function evaluate(SchedulingContext $context, ?ConstraintConfiguration $configuration): ConstraintResult
    {
        $dateIssues = [];

        if ($context->data->occurrenceDate !== null) {
            if ($context->data->occurrenceDate->lt($context->academicPeriod->starts_on)
                || $context->data->occurrenceDate->gt($context->academicPeriod->ends_on)) {
                $dateIssues[] = $this->issue(
                    'calendar_date_out_of_period',
                    ConstraintSeverity::Hard,
                    'occurrence_date',
                    'The occurrence date must fall within the timetable academic period.',
                );
            }

            if ($context->data->occurrenceDate->dayOfWeekIso !== $context->data->weekday) {
                $dateIssues[] = $this->issue(
                    'occurrence_date_weekday_mismatch',
                    ConstraintSeverity::Hard,
                    'occurrence_date',
                    'The occurrence date must match the selected recurring weekday.',
                );
            }

            if ($dateIssues !== []) {
                return new ConstraintResult($dateIssues);
            }
        }

        $dateException = $context->data->occurrenceDate === null
            ? null
            : $context->calendarExceptions->first(
                fn (CalendarException $exception): bool => $exception->date->toDateString() === $context->data->occurrenceDate->toDateString(),
            );
        $calendar = $context->calendars->get($context->data->weekday);
        $issues = [];

        if ($dateException?->kind === CalendarExceptionKind::Holiday
            || $dateException?->kind === CalendarExceptionKind::Blocked) {
            $issues[] = $this->issue(
                'calendar_blocked_date',
                ConstraintSeverity::Hard,
                'occurrence_date',
                'The selected date is blocked by the academic calendar.',
                details: [
                    'source' => 'calendar_exception',
                    'exception_id' => $dateException->getKey(),
                    'date' => $dateException->date->toDateString(),
                    'name' => $dateException->name,
                ],
            );

            return new ConstraintResult($issues);
        }

        if ($dateException?->kind === CalendarExceptionKind::Teaching) {
            if ($dateException->starts_at_minute === null
                || ($dateException->starts_at_minute <= $context->data->startsAtMinute
                    && $dateException->ends_at_minute >= $context->data->endsAtMinute)) {
                return new ConstraintResult;
            }

            $issues[] = $this->issue(
                'calendar_exception_operating_hours',
                ConstraintSeverity::Hard,
                'starts_at_minute',
                'The scheduled time is outside the exceptional teaching window.',
                details: [
                    'source' => 'calendar_exception',
                    'exception_id' => $dateException->getKey(),
                    'date' => $dateException->date->toDateString(),
                    'exception_starts_at_minute' => $dateException->starts_at_minute,
                    'exception_ends_at_minute' => $dateException->ends_at_minute,
                ],
            );

            return new ConstraintResult($issues);
        }

        if (! $calendar instanceof AcademicCalendar) {
            $issues[] = $this->issue(
                $this->code(),
                ConstraintSeverity::Hard,
                'weekday',
                'The academic period has no operating hours for this weekday.',
                details: ['source' => 'academic_calendar'],
            );
        } elseif ($calendar->starts_at_minute > $context->data->startsAtMinute
            || $calendar->ends_at_minute < $context->data->endsAtMinute) {
            $issues[] = $this->issue(
                $this->code(),
                ConstraintSeverity::Hard,
                'starts_at_minute',
                'The scheduled time is outside the academic period operating hours.',
                details: [
                    'source' => 'academic_calendar',
                    'calendar_starts_at_minute' => $calendar->starts_at_minute,
                    'calendar_ends_at_minute' => $calendar->ends_at_minute,
                ],
            );
        }

        if ($context->data->occurrenceDate !== null) {
            return new ConstraintResult($issues);
        }

        foreach ($context->calendarExceptions as $exception) {
            if (! in_array($exception->kind, [CalendarExceptionKind::Holiday, CalendarExceptionKind::Blocked], true)
                || $exception->date->dayOfWeekIso !== $context->data->weekday) {
                continue;
            }

            $issues[] = $this->issue(
                'calendar_blocked_date',
                ConstraintSeverity::Hard,
                'weekday',
                'The recurring schedule includes a blocked academic calendar date.',
                details: [
                    'source' => 'calendar_exception',
                    'exception_id' => $exception->getKey(),
                    'date' => $exception->date->toDateString(),
                    'name' => $exception->name,
                ],
            );
        }

        return new ConstraintResult($issues);
    }
}
