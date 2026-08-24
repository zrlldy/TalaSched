<?php

namespace App\Scheduling\Constraints;

use App\Enums\ConstraintSeverity;
use App\Models\ConstraintConfiguration;
use App\Scheduling\ConstraintResult;
use App\Scheduling\SchedulingContext;

class VersionEditableConstraintHandler extends AbstractConstraintHandler
{
    public function code(): string
    {
        return 'version_not_editable';
    }

    public function defaultSeverity(): ConstraintSeverity
    {
        return ConstraintSeverity::Hard;
    }

    public function evaluate(SchedulingContext $context, ?ConstraintConfiguration $configuration): ConstraintResult
    {
        if (! $context->checkVersionEditability || $context->version->status->isEditable()) {
            return ConstraintResult::empty();
        }

        return ConstraintResult::from($this->issue(
            $this->code(),
            ConstraintSeverity::Hard,
            'timetable_version_id',
            'Published or review-locked timetable versions cannot be edited.',
        ));
    }
}
