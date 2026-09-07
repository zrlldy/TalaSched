<?php

use App\Actions\ValidateTemplateWorkbookMapping;
use App\Data\TemplateWorkbookInspection;
use App\Models\Organization;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->organization = Organization::factory()->create(['scheduling_granularity' => 30]);
    $this->inspection = new TemplateWorkbookInspection([
        [
            'dimensions' => ['columns' => 5, 'range' => 'A1:E20', 'rows' => 20],
            'merged_cell_count' => 0,
            'merged_cells' => [],
            'merged_cells_truncated' => false,
            'name' => 'Schedule',
            'preview' => ['columns' => ['A', 'B', 'C', 'D', 'E'], 'rows' => [], 'truncated' => false],
        ],
    ]);
});

test('validates a typed workbook mapping against an inspected worksheet', function (): void {
    $mapping = app(ValidateTemplateWorkbookMapping::class)->handle(
        $this->organization,
        $this->inspection,
        validTemplateWorkbookMapping(),
    );

    expect($mapping->worksheet)->toBe('Schedule')
        ->and($mapping->timetableArea->startCell)->toBe('B2')
        ->and($mapping->timetableArea->endCell)->toBe('F10')
        ->and($mapping->dayColumns)->toBe([
            ['column' => 'B', 'weekday' => 1],
            ['column' => 'C', 'weekday' => 2],
        ])
        ->and($mapping->timeRows)->toBe([
            ['row' => 3, 'start_minute' => 480],
            ['row' => 4, 'start_minute' => 510],
        ])
        ->and($mapping->placeholders)->toBe([
            ['cell' => 'A15', 'key' => 'organization_name'],
        ])
        ->and($mapping->signatoryFields)->toBe([
            [
                'name_cell' => 'B16',
                'position_cell' => 'B17',
                'role' => 'prepared_by',
                'signature_cell' => 'B18',
            ],
        ]);
});

test('rejects worksheet, time, and fixed-cell mapping conflicts', function (): void {
    $mapping = validTemplateWorkbookMapping();
    $mapping['worksheet'] = 'Missing worksheet';
    $mapping['day_columns'][] = ['weekday' => 1, 'column' => 'B'];
    $mapping['time_rows'][1]['start_minute'] = 495;
    $mapping['placeholders'][0]['cell'] = 'B3';
    $mapping['placeholders'][0]['key'] = 'unsupported_placeholder';

    try {
        app(ValidateTemplateWorkbookMapping::class)->handle($this->organization, $this->inspection, $mapping);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKeys([
            'worksheet',
            'day_columns.2.weekday',
            'day_columns.2.column',
            'time_rows.1.start_minute',
            'placeholders.0.key',
            'placeholders.0.cell',
        ]);

        return;
    }

    $this->fail('Invalid mappings must raise a validation exception.');
});

/** @return array<string, mixed> */
function validTemplateWorkbookMapping(): array
{
    return [
        'worksheet' => 'Schedule',
        'timetable_area' => [
            'start_cell' => 'B2',
            'end_cell' => 'F10',
        ],
        'day_columns' => [
            ['weekday' => 1, 'column' => 'B'],
            ['weekday' => 2, 'column' => 'C'],
        ],
        'time_rows' => [
            ['row' => 3, 'start_minute' => 480],
            ['row' => 4, 'start_minute' => 510],
        ],
        'schedule_cell' => [
            'format' => '{{subject_code}}\n{{room_code}}',
        ],
        'placeholders' => [
            ['key' => 'organization_name', 'cell' => 'A15'],
        ],
        'signatory_fields' => [
            [
                'role' => 'prepared_by',
                'name_cell' => 'B16',
                'position_cell' => 'B17',
                'signature_cell' => 'B18',
            ],
        ],
    ];
}
