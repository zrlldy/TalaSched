<?php

namespace App\Scheduling;

use App\Enums\ConstraintSeverity;
use App\Models\ConstraintConfiguration;

interface ConstraintHandler
{
    public function code(): string;

    public function defaultSeverity(): ConstraintSeverity;

    public function supports(SchedulingContext $context, ?ConstraintConfiguration $configuration): bool;

    public function evaluate(SchedulingContext $context, ?ConstraintConfiguration $configuration): ConstraintResult;
}
