<?php

namespace App\Scheduling;

use Carbon\CarbonImmutable;

final readonly class TimetableViewFilters
{
    /**
     * @param  string  $scope  One of organization, teacher, student_group, room, or unit.
     */
    public function __construct(
        public string $scope,
        public ?string $versionPublicId,
        public ?string $resourcePublicId,
        public ?string $unitPublicId,
        public ?CarbonImmutable $date,
        public ?int $weekday,
    ) {}
}
