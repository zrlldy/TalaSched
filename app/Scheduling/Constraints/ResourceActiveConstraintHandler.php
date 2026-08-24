<?php

namespace App\Scheduling\Constraints;

use App\Enums\ConstraintSeverity;
use App\Models\ConstraintConfiguration;
use App\Scheduling\ConstraintResult;
use App\Scheduling\SchedulingContext;

class ResourceActiveConstraintHandler extends AbstractConstraintHandler
{
    public function code(): string
    {
        return 'resource_inactive';
    }

    public function defaultSeverity(): ConstraintSeverity
    {
        return ConstraintSeverity::Hard;
    }

    public function evaluate(SchedulingContext $context, ?ConstraintConfiguration $configuration): ConstraintResult
    {
        $issues = [];

        foreach ($context->data->resources as $assignment) {
            $resource = $context->resource($assignment['resource_id']);

            if ($resource === null || $resource->is_active) {
                continue;
            }

            $issues[] = $this->issue(
                $this->code(),
                ConstraintSeverity::Hard,
                'resources',
                'The selected resource is inactive.',
                $resource,
            );
        }

        return new ConstraintResult($issues);
    }
}
