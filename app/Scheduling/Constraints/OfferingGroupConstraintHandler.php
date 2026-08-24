<?php

namespace App\Scheduling\Constraints;

use App\Enums\ConstraintSeverity;
use App\Enums\ScheduleResourceRole;
use App\Models\ConstraintConfiguration;
use App\Scheduling\ConstraintResult;
use App\Scheduling\SchedulingContext;

class OfferingGroupConstraintHandler extends AbstractConstraintHandler
{
    public function code(): string
    {
        return 'offering_group_mismatch';
    }

    public function defaultSeverity(): ConstraintSeverity
    {
        return ConstraintSeverity::Hard;
    }

    public function evaluate(SchedulingContext $context, ?ConstraintConfiguration $configuration): ConstraintResult
    {
        $groupResource = $context->offeringComponent->offering->studentGroup->resource;
        $assignedGroupIds = collect($context->data->resources)
            ->where('role', ScheduleResourceRole::StudentGroup->value)
            ->pluck('resource_id');

        if ($assignedGroupIds->contains($groupResource->getKey())) {
            return ConstraintResult::empty();
        }

        return ConstraintResult::from($this->issue(
            $this->code(),
            ConstraintSeverity::Hard,
            'resources',
            'The offering must be scheduled for its assigned student group.',
            $groupResource,
        ));
    }
}
