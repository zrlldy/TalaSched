<?php

use App\Data\Imports\FacultyImportRow;
use App\Data\Imports\OfferingImportRow;
use App\Data\Imports\RoomImportRow;
use App\Data\Imports\SubjectImportRow;
use App\Enums\DeliveryMode;
use App\Enums\FacultyEmploymentType;
use App\Enums\SubjectOfferingStatus;
use InvalidArgumentException;

test('controlled import rows normalize supported scalar and enum values', function (): void {
    $faculty = FacultyImportRow::fromArray([
        'resource_name' => '  Dr. Ada Lovelace ',
        'employee_number' => 17,
        'employment_type' => FacultyEmploymentType::FullTime->value,
        'maximum_daily_minutes' => '480',
        'maximum_weekly_minutes' => 2400,
    ]);
    $room = RoomImportRow::fromArray([
        'resource_name' => 'Science Lab 1',
        'code' => 'SCI-101',
        'name' => 'Science Laboratory 1',
        'room_type_code' => 'LAB',
        'capacity' => '32',
        'delivery_mode' => DeliveryMode::Physical->value,
    ]);
    $subject = SubjectImportRow::fromArray([
        'code' => 'CS-101',
        'name' => 'Programming I',
        'units' => '3.0',
    ]);
    $offering = OfferingImportRow::fromArray([
        'academic_year_name' => '2026-2027',
        'period_name' => 'First semester',
        'subject_code' => 'CS-101',
        'student_group_code' => 'BSCS-1A',
        'expected_enrollment' => '30',
        'status' => SubjectOfferingStatus::Draft->value,
    ]);

    expect($faculty->resourceName)->toBe('Dr. Ada Lovelace')
        ->and($faculty->employeeNumber)->toBe('17')
        ->and($faculty->employmentType)->toBe(FacultyEmploymentType::FullTime)
        ->and($faculty->maximumWeeklyMinutes)->toBe(2400)
        ->and($room->capacity)->toBe(32)
        ->and($room->deliveryMode)->toBe(DeliveryMode::Physical)
        ->and($subject->units)->toBe(3.0)
        ->and($offering->expectedEnrollment)->toBe(30)
        ->and($offering->status)->toBe(SubjectOfferingStatus::Draft);
});

test('import row contracts publish fixed column layouts', function (): void {
    expect(FacultyImportRow::columns())->toContain('resource_name', 'academic_unit_code')
        ->and(RoomImportRow::columns())->toContain('room_type_code', 'delivery_mode')
        ->and(SubjectImportRow::columns())->toBe(['code', 'name', 'units', 'description'])
        ->and(OfferingImportRow::columns())->toContain('academic_year_name', 'student_group_code', 'status');
});

test('import row contracts reject missing and unsupported values', function (): void {
    expect(fn () => SubjectImportRow::fromArray(['name' => 'Missing code']))
        ->toThrow(InvalidArgumentException::class, 'code')
        ->and(fn () => RoomImportRow::fromArray([
            'resource_name' => 'Room',
            'code' => 'R-1',
            'name' => 'Room',
            'room_type_code' => 'CLASS',
            'delivery_mode' => 'telepathic',
        ]))->toThrow(InvalidArgumentException::class, 'delivery_mode')
        ->and(fn () => OfferingImportRow::fromArray([
            'academic_year_name' => '2026-2027',
            'period_name' => 'Term 1',
            'subject_code' => 'CS-101',
            'student_group_code' => 'BSCS-1A',
            'expected_enrollment' => 'many',
            'status' => SubjectOfferingStatus::Draft->value,
        ]))->toThrow(InvalidArgumentException::class, 'expected_enrollment');
});
