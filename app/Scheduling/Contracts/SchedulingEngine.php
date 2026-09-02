<?php

namespace App\Scheduling\Contracts;

use App\Scheduling\Data\GenerationResult;
use App\Scheduling\Data\SchedulingProblem;

interface SchedulingEngine
{
    public function generate(
        SchedulingProblem $problem,
        GenerationCancellation $cancellation,
        GenerationProgressReporter $progressReporter,
    ): GenerationResult;
}
