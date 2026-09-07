<?php

namespace App\Enums;

enum TemplatePlaceholder: string
{
    case OrganizationName = 'organization_name';
    case OrganizationTimezone = 'organization_timezone';
    case AcademicYearName = 'academic_year_name';
    case AcademicPeriodName = 'academic_period_name';
    case TimetableName = 'timetable_name';
    case TimetableVersionNumber = 'timetable_version_number';
    case TimetableVersionStatus = 'timetable_version_status';
    case PublishedAt = 'published_at';
    case GeneratedAt = 'generated_at';

    public function label(): string
    {
        return match ($this) {
            self::OrganizationName => 'Organization name',
            self::OrganizationTimezone => 'Organization timezone',
            self::AcademicYearName => 'Academic year name',
            self::AcademicPeriodName => 'Academic period name',
            self::TimetableName => 'Timetable name',
            self::TimetableVersionNumber => 'Timetable version number',
            self::TimetableVersionStatus => 'Timetable version status',
            self::PublishedAt => 'Published at',
            self::GeneratedAt => 'Generated at',
        };
    }
}
