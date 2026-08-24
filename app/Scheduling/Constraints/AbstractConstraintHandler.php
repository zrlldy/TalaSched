<?php

namespace App\Scheduling\Constraints;

use App\Enums\ConstraintSeverity;
use App\Models\ConstraintConfiguration;
use App\Models\SchedulingResource;
use App\Scheduling\ConstraintHandler;
use App\Scheduling\ConstraintIssue;
use App\Scheduling\SchedulingContext;

abstract class AbstractConstraintHandler implements ConstraintHandler
{
    public function supports(SchedulingContext $context, ?ConstraintConfiguration $configuration): bool
    {
        return true;
    }

    /** @param array<string, mixed> $details */
    protected function issue(
        string $code,
        ConstraintSeverity $severity,
        string $field,
        string $message,
        ?SchedulingResource $resource = null,
        ?string $conflictingEntryId = null,
        array $details = [],
    ): ConstraintIssue {
        return new ConstraintIssue(
            code: $code,
            severity: $severity,
            field: $field,
            message: $message,
            resource: $resource === null ? null : [
                'id' => $resource->public_id,
                'name' => $resource->name,
            ],
            conflictingEntryId: $conflictingEntryId,
            details: $details,
        );
    }
}
