<?php

namespace App\Data\Imports;

use App\Enums\SubjectOfferingStatus;

final readonly class OfferingImportRow
{
    public function __construct(
        public string $academicYearName,
        public string $periodName,
        public string $subjectCode,
        public string $studentGroupCode,
        public ?string $owningUnitCode,
        public ?string $code,
        public int $expectedEnrollment,
        public SubjectOfferingStatus $status,
    ) {}

    /** @return list<string> */
    public static function columns(): array
    {
        return [
            'academic_year_name',
            'period_name',
            'subject_code',
            'student_group_code',
            'owning_unit_code',
            'code',
            'expected_enrollment',
            'status',
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        $status = ImportRowValue::requiredString($row, 'status');

        return new self(
            academicYearName: ImportRowValue::requiredString($row, 'academic_year_name'),
            periodName: ImportRowValue::requiredString($row, 'period_name'),
            subjectCode: ImportRowValue::requiredString($row, 'subject_code'),
            studentGroupCode: ImportRowValue::requiredString($row, 'student_group_code'),
            owningUnitCode: ImportRowValue::optionalString($row, 'owning_unit_code'),
            code: ImportRowValue::optionalString($row, 'code'),
            expectedEnrollment: ImportRowValue::optionalInteger($row, 'expected_enrollment') ?? 0,
            status: ImportRowValue::enumValue(
                $status,
                'status',
                fn (string $value): ?SubjectOfferingStatus => SubjectOfferingStatus::tryFrom($value),
            ),
        );
    }
}
