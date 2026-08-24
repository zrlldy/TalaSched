<?php

namespace App\Scheduling;

use App\Models\Organization;

class ValidateScheduleEntry
{
    public function __construct(
        private SchedulingContextFactory $contextFactory,
        private ConstraintRegistry $registry,
    ) {}

    public function evaluate(
        Organization $organization,
        ScheduleEntryData $data,
        bool $checkVersionEditability = true,
    ): ConstraintResult {
        $context = $this->contextFactory->make($organization, $data, $checkVersionEditability);

        return $this->registry->evaluate($context);
    }

    /** @return array<int, array<string, mixed>> */
    public function handle(Organization $organization, ScheduleEntryData $data): array
    {
        return $this->evaluate($organization, $data)->toArray();
    }
}
