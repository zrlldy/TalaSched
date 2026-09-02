<?php

namespace App\Scheduling\Contracts;

interface GenerationCancellation
{
    public function isCancellationRequested(): bool;
}
