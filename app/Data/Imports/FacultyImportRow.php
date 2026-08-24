<?php

namespace App\Data\Imports;

use App\Enums\FacultyEmploymentType;

final readonly class FacultyImportRow
{
    public function __construct(
        public string $resourceName,
        public ?string $employeeNumber,
        public ?string $position,
        public ?FacultyEmploymentType $employmentType,
        public ?int $maximumDailyMinutes,
        public ?int $maximumWeeklyMinutes,
        public ?string $academicUnitCode,
    ) {}

    /** @return list<string> */
    public static function columns(): array
    {
        return [
            'resource_name',
            'employee_number',
            'position',
            'employment_type',
            'maximum_daily_minutes',
            'maximum_weekly_minutes',
            'academic_unit_code',
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        $employmentType = ImportRowValue::optionalString($row, 'employment_type');

        return new self(
            resourceName: ImportRowValue::requiredString($row, 'resource_name'),
            employeeNumber: ImportRowValue::optionalString($row, 'employee_number'),
            position: ImportRowValue::optionalString($row, 'position'),
            employmentType: ImportRowValue::enumValue(
                $employmentType,
                'employment_type',
                fn (string $value): ?FacultyEmploymentType => FacultyEmploymentType::tryFrom($value),
            ),
            maximumDailyMinutes: ImportRowValue::optionalInteger($row, 'maximum_daily_minutes'),
            maximumWeeklyMinutes: ImportRowValue::optionalInteger($row, 'maximum_weekly_minutes'),
            academicUnitCode: ImportRowValue::optionalString($row, 'academic_unit_code'),
        );
    }
}
