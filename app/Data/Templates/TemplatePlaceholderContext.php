<?php

namespace App\Data\Templates;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Organization;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use Carbon\CarbonInterface;

final readonly class TemplatePlaceholderContext
{
    public function __construct(
        public Organization $organization,
        public AcademicYear $academicYear,
        public AcademicPeriod $academicPeriod,
        public Timetable $timetable,
        public TimetableVersion $timetableVersion,
        public CarbonInterface $generatedAt,
    ) {}
}
