<?php

namespace App\Scheduling\Constraints;

use App\Enums\ConstraintSeverity;
use App\Models\ConstraintConfiguration;
use App\Scheduling\ConstraintIssue;
use App\Scheduling\ConstraintResult;
use App\Scheduling\SchedulingContext;
use Carbon\CarbonImmutable;

class OrganizationBlockedTimeConstraintHandler extends AbstractConstraintHandler
{
    public function code(): string
    {
        return 'organization_blocked_time';
    }

    public function defaultSeverity(): ConstraintSeverity
    {
        return ConstraintSeverity::Hard;
    }

    public function evaluate(SchedulingContext $context, ?ConstraintConfiguration $configuration): ConstraintResult
    {
        $settings = $configuration?->getAttribute('configuration');

        if ($settings === null) {
            return ConstraintResult::empty();
        }

        if (! is_array($settings)) {
            return ConstraintResult::from($this->invalidConfigurationIssue());
        }

        $blockedTimes = $settings['blocked_times'] ?? [];

        if ($blockedTimes === []) {
            return ConstraintResult::empty();
        }

        if (! is_array($blockedTimes)) {
            return ConstraintResult::from($this->issue(
                'organization_blocked_time_configuration',
                ConstraintSeverity::Hard,
                'configuration',
                'The organization blocked-time configuration is invalid.',
            ));
        }

        $issues = [];

        foreach ($blockedTimes as $blockedTime) {
            if (! is_array($blockedTime)) {
                $issues[] = $this->invalidConfigurationIssue();

                continue;
            }

            $weekday = $blockedTime['weekday'] ?? null;
            $startsAtMinute = $blockedTime['starts_at_minute'] ?? null;
            $endsAtMinute = $blockedTime['ends_at_minute'] ?? null;
            $effectiveFromValue = $blockedTime['effective_from'] ?? null;
            $effectiveUntilValue = $blockedTime['effective_until'] ?? null;
            $effectiveFrom = $this->date($effectiveFromValue);
            $effectiveUntil = $this->date($effectiveUntilValue);

            if (! is_int($weekday) || ! is_int($startsAtMinute) || ! is_int($endsAtMinute)
                || $weekday < 1 || $weekday > 7
                || $startsAtMinute < 0 || $startsAtMinute >= $endsAtMinute
                || $endsAtMinute > 1440
                || ($effectiveFromValue !== null && $effectiveFrom === null)
                || ($effectiveUntilValue !== null && $effectiveUntil === null)
                || ($effectiveFrom !== null && $effectiveUntil !== null && $effectiveFrom->greaterThan($effectiveUntil))) {
                $issues[] = $this->invalidConfigurationIssue();

                continue;
            }

            if ($weekday !== $context->data->weekday
                || $startsAtMinute >= $context->data->endsAtMinute
                || $endsAtMinute <= $context->data->startsAtMinute
                || ! $this->matchesEffectiveDate($blockedTime, $context)) {
                continue;
            }

            $issues[] = $this->issue(
                $this->code(),
                ConstraintSeverity::Hard,
                'starts_at_minute',
                'The organization is blocked during the selected schedule time.',
                details: [
                    'source' => 'organization_blocked_time',
                    'name' => is_string($blockedTime['name'] ?? null) ? $blockedTime['name'] : null,
                    'weekday' => $weekday,
                    'starts_at_minute' => $startsAtMinute,
                    'ends_at_minute' => $endsAtMinute,
                ],
            );
        }

        return new ConstraintResult($issues);
    }

    /** @param array<string, mixed> $blockedTime */
    private function matchesEffectiveDate(array $blockedTime, SchedulingContext $context): bool
    {
        $from = $this->date($blockedTime['effective_from'] ?? null);
        $until = $this->date($blockedTime['effective_until'] ?? null);
        $date = $context->data->occurrenceDate;

        if ($date !== null) {
            return ($from === null || $date->greaterThanOrEqualTo($from))
                && ($until === null || $date->lessThanOrEqualTo($until));
        }

        return ($from === null || $from->lessThanOrEqualTo($context->academicPeriod->ends_on))
            && ($until === null || $until->greaterThanOrEqualTo($context->academicPeriod->starts_on));
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function invalidConfigurationIssue(): ConstraintIssue
    {
        return $this->issue(
            'organization_blocked_time_configuration',
            ConstraintSeverity::Hard,
            'configuration',
            'The organization blocked-time configuration is invalid.',
        );
    }
}
