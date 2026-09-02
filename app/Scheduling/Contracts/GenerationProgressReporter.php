<?php

namespace App\Scheduling\Contracts;

use App\Scheduling\Data\GenerationProgress;

interface GenerationProgressReporter
{
    public function report(GenerationProgress $progress): void;
}
