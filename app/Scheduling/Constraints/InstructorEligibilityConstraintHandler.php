<?php

namespace App\Scheduling\Constraints;

use App\Enums\ConstraintSeverity;
use App\Enums\ScheduleResourceRole;
use App\Models\ConstraintConfiguration;
use App\Models\FacultyProfile;
use App\Scheduling\ConstraintResult;
use App\Scheduling\SchedulingContext;

class InstructorEligibilityConstraintHandler extends AbstractConstraintHandler
{
    public function code(): string
    {
        return 'instructor_eligibility';
    }

    public function defaultSeverity(): ConstraintSeverity
    {
        return ConstraintSeverity::Hard;
    }

    public function evaluate(SchedulingContext $context, ?ConstraintConfiguration $configuration): ConstraintResult
    {
        $issues = [];
        $eligibleInstructorIds = $context->offeringComponent->instructors->modelKeys();

        foreach ($context->data->resources as $assignment) {
            if ($assignment['role'] !== ScheduleResourceRole::Instructor->value) {
                continue;
            }

            $resource = $context->resource($assignment['resource_id']);
            $faculty = $context->facultyProfiles->get($assignment['resource_id']);

            if (! $faculty instanceof FacultyProfile || ! in_array($faculty->getKey(), $eligibleInstructorIds, true)) {
                $issues[] = $this->issue(
                    $this->code(),
                    ConstraintSeverity::Hard,
                    'resources',
                    'The selected instructor is not eligible for this offering component.',
                    $resource,
                );
            }
        }

        return new ConstraintResult($issues);
    }
}
