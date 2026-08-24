<?php

namespace App\Scheduling\Constraints;

use App\Enums\ConstraintSeverity;
use App\Enums\ResourceType;
use App\Enums\ScheduleResourceRole;
use App\Models\ConstraintConfiguration;
use App\Scheduling\ConstraintResult;
use App\Scheduling\SchedulingContext;

class ResourceRoleConstraintHandler extends AbstractConstraintHandler
{
    public function code(): string
    {
        return 'resource_role_mismatch';
    }

    public function defaultSeverity(): ConstraintSeverity
    {
        return ConstraintSeverity::Hard;
    }

    public function evaluate(SchedulingContext $context, ?ConstraintConfiguration $configuration): ConstraintResult
    {
        $expectedTypes = [
            ScheduleResourceRole::Instructor->value => ResourceType::Faculty,
            ScheduleResourceRole::StudentGroup->value => ResourceType::StudentGroup,
            ScheduleResourceRole::Room->value => ResourceType::Room,
            ScheduleResourceRole::Equipment->value => ResourceType::Equipment,
        ];
        $issues = [];

        foreach ($context->data->resources as $assignment) {
            $resource = $context->resource($assignment['resource_id']);
            $expectedType = $expectedTypes[$assignment['role']] ?? null;

            if ($resource === null || $expectedType !== $resource->type) {
                $issues[] = $this->issue(
                    $this->code(),
                    ConstraintSeverity::Hard,
                    'resources',
                    'The selected resource does not match its scheduling role.',
                    $resource,
                );
            }
        }

        return new ConstraintResult($issues);
    }
}
