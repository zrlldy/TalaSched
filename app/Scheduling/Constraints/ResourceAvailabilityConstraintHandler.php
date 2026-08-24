<?php

namespace App\Scheduling\Constraints;

use App\Enums\ConstraintSeverity;
use App\Models\CalendarException;
use App\Models\ConstraintConfiguration;
use App\Scheduling\ConstraintIssue;
use App\Scheduling\ConstraintResult;
use App\Scheduling\ResourceAvailabilityResolver;
use App\Scheduling\SchedulingContext;

class ResourceAvailabilityConstraintHandler extends AbstractConstraintHandler
{
    public function __construct(private ResourceAvailabilityResolver $resolver) {}

    public function code(): string
    {
        return 'resource_unavailable';
    }

    public function defaultSeverity(): ConstraintSeverity
    {
        return ConstraintSeverity::Hard;
    }

    public function evaluate(SchedulingContext $context, ?ConstraintConfiguration $configuration): ConstraintResult
    {
        $calendarException = $context->data->occurrenceDate === null
            ? null
            : $context->calendarExceptions->first(
                fn (CalendarException $exception): bool => $exception->date->toDateString() === $context->data->occurrenceDate->toDateString(),
            );
        $issues = $this->resolver->issues(
            $context->organization,
            $context->version,
            $context->resources,
            $context->data->weekday,
            $context->data->startsAtMinute,
            $context->data->endsAtMinute,
            $context->data->occurrenceDate,
            $calendarException,
        );

        return new ConstraintResult(array_values(array_map(
            fn (array $issue): ConstraintIssue => new ConstraintIssue(
                code: $issue['code'],
                severity: ConstraintSeverity::from($issue['severity']),
                field: $issue['field'],
                message: $issue['message'],
                resource: $this->resource($issue['resource'] ?? null),
                conflictingEntryId: is_string($issue['conflicting_entry_id'] ?? null) ? $issue['conflicting_entry_id'] : null,
                details: is_array($issue['details'] ?? null) ? $issue['details'] : [],
            ),
            $issues,
        )));
    }

    /** @return array{id: string|null, name: string|null}|null */
    private function resource(mixed $resource): ?array
    {
        if (! is_array($resource)) {
            return null;
        }

        $id = $resource['id'] ?? null;
        $name = $resource['name'] ?? null;

        return [
            'id' => is_string($id) || $id === null ? $id : null,
            'name' => is_string($name) || $name === null ? $name : null,
        ];
    }
}
