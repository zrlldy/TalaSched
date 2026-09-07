<?php

namespace App\Data\Templates;

final readonly class TemplateWorkbookMapping
{
    /**
     * @param  list<array{column: string, weekday: int}>  $dayColumns
     * @param  list<array{cell: string, key: string}>  $placeholders
     * @param  list<array{name_cell: string, position_cell: string|null, role: string, signature_cell: string|null}>  $signatoryFields
     * @param  list<array{row: int, start_minute: int}>  $timeRows
     */
    public function __construct(
        public string $worksheet,
        public TemplateCellRange $timetableArea,
        public array $dayColumns,
        public array $timeRows,
        public string $scheduleCellFormat,
        public array $placeholders,
        public array $signatoryFields,
    ) {}

    /**
     * @return array{
     *     worksheet: string,
     *     timetable_area: array{start_cell: string, end_cell: string},
     *     day_columns: list<array{column: string, weekday: int}>,
     *     time_rows: list<array{row: int, start_minute: int}>,
     *     schedule_cell: array{format: string},
     *     placeholders: list<array{cell: string, key: string}>,
     *     signatory_fields: list<array{name_cell: string, position_cell: string|null, role: string, signature_cell: string|null}>
     * }
     */
    public function toArray(): array
    {
        return [
            'worksheet' => $this->worksheet,
            'timetable_area' => [
                'start_cell' => $this->timetableArea->startCell,
                'end_cell' => $this->timetableArea->endCell,
            ],
            'day_columns' => $this->dayColumns,
            'time_rows' => $this->timeRows,
            'schedule_cell' => ['format' => $this->scheduleCellFormat],
            'placeholders' => $this->placeholders,
            'signatory_fields' => $this->signatoryFields,
        ];
    }
}
