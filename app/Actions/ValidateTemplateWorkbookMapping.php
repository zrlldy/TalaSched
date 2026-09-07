<?php

namespace App\Actions;

use App\Data\Templates\TemplateCellRange;
use App\Data\Templates\TemplateWorkbookMapping;
use App\Data\TemplateWorkbookInspection;
use App\Enums\TemplatePlaceholder;
use App\Models\Organization;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;

class ValidateTemplateWorkbookMapping
{
    /**
     * @param  array<string, mixed>  $mapping
     */
    public function handle(
        Organization $organization,
        TemplateWorkbookInspection $inspection,
        array $mapping,
    ): TemplateWorkbookMapping {
        $validated = Validator::make($mapping, $this->rules())->validate();
        $templateMapping = $this->mapping($validated);
        $errors = [];

        if (! $this->worksheetExists($inspection, $templateMapping->worksheet)) {
            $errors['worksheet'] = 'The selected worksheet is not present in the inspected workbook.';
        }

        $start = $this->coordinateIndexes($templateMapping->timetableArea->startCell, 'timetable_area.start_cell', $errors);
        $end = $this->coordinateIndexes($templateMapping->timetableArea->endCell, 'timetable_area.end_cell', $errors);

        if ($start !== null && $end !== null && ($start['column'] > $end['column'] || $start['row'] > $end['row'])) {
            $errors['timetable_area'] = 'The timetable area must end after it starts.';
        }

        $this->validateDayColumns($templateMapping, $start, $end, $errors);
        $this->validateTimeRows($organization, $templateMapping, $start, $end, $errors);
        $this->validateFixedFields($templateMapping, $start, $end, $errors);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $templateMapping;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rules(): array
    {
        return [
            'worksheet' => ['required', 'string', 'max:31'],
            'timetable_area' => ['required', 'array:start_cell,end_cell'],
            'timetable_area.start_cell' => ['required', 'string', 'regex:/^[A-Za-z]{1,3}[1-9][0-9]{0,6}$/'],
            'timetable_area.end_cell' => ['required', 'string', 'regex:/^[A-Za-z]{1,3}[1-9][0-9]{0,6}$/'],
            'day_columns' => ['required', 'array', 'min:1', 'max:7'],
            'day_columns.*' => ['required', 'array:weekday,column'],
            'day_columns.*.weekday' => ['required', 'integer', 'between:1,7'],
            'day_columns.*.column' => ['required', 'string', 'regex:/^[A-Za-z]{1,3}$/'],
            'time_rows' => ['required', 'array', 'min:1', 'max:500'],
            'time_rows.*' => ['required', 'array:row,start_minute'],
            'time_rows.*.row' => ['required', 'integer', 'min:1', 'max:1048576'],
            'time_rows.*.start_minute' => ['required', 'integer', 'between:0,1439'],
            'schedule_cell' => ['required', 'array:format'],
            'schedule_cell.format' => ['required', 'string', 'max:1000'],
            'placeholders' => ['nullable', 'array', 'max:100'],
            'placeholders.*' => ['required', 'array:key,cell'],
            'placeholders.*.key' => ['required', 'string', 'alpha_dash', 'max:64'],
            'placeholders.*.cell' => ['required', 'string', 'regex:/^[A-Za-z]{1,3}[1-9][0-9]{0,6}$/'],
            'signatory_fields' => ['nullable', 'array', 'max:20'],
            'signatory_fields.*' => ['required', 'array:role,name_cell,position_cell,signature_cell'],
            'signatory_fields.*.role' => ['required', 'string', 'alpha_dash', 'max:64'],
            'signatory_fields.*.name_cell' => ['required', 'string', 'regex:/^[A-Za-z]{1,3}[1-9][0-9]{0,6}$/'],
            'signatory_fields.*.position_cell' => ['nullable', 'string', 'regex:/^[A-Za-z]{1,3}[1-9][0-9]{0,6}$/'],
            'signatory_fields.*.signature_cell' => ['nullable', 'string', 'regex:/^[A-Za-z]{1,3}[1-9][0-9]{0,6}$/'],
        ];
    }

    /** @param array<string, mixed> $validated */
    private function mapping(array $validated): TemplateWorkbookMapping
    {
        $timetableArea = $this->arrayValue($validated, 'timetable_area');
        $dayColumns = [];

        foreach ($this->listValue($validated, 'day_columns') as $dayColumn) {
            $dayColumns[] = [
                'column' => Str::upper($this->stringValue($dayColumn, 'column')),
                'weekday' => $this->integerValue($dayColumn, 'weekday'),
            ];
        }

        $timeRows = [];

        foreach ($this->listValue($validated, 'time_rows') as $timeRow) {
            $timeRows[] = [
                'row' => $this->integerValue($timeRow, 'row'),
                'start_minute' => $this->integerValue($timeRow, 'start_minute'),
            ];
        }

        $scheduleCell = $this->arrayValue($validated, 'schedule_cell');
        $placeholders = [];

        foreach ($this->listValue($validated, 'placeholders') as $placeholder) {
            $placeholders[] = [
                'cell' => Str::upper($this->stringValue($placeholder, 'cell')),
                'key' => $this->stringValue($placeholder, 'key'),
            ];
        }

        $signatoryFields = [];

        foreach ($this->listValue($validated, 'signatory_fields') as $signatoryField) {
            $signatoryFields[] = [
                'name_cell' => Str::upper($this->stringValue($signatoryField, 'name_cell')),
                'position_cell' => $this->nullableCell($signatoryField, 'position_cell'),
                'role' => $this->stringValue($signatoryField, 'role'),
                'signature_cell' => $this->nullableCell($signatoryField, 'signature_cell'),
            ];
        }

        return new TemplateWorkbookMapping(
            worksheet: $this->stringValue($validated, 'worksheet'),
            timetableArea: new TemplateCellRange(
                startCell: Str::upper($this->stringValue($timetableArea, 'start_cell')),
                endCell: Str::upper($this->stringValue($timetableArea, 'end_cell')),
            ),
            dayColumns: $dayColumns,
            timeRows: $timeRows,
            scheduleCellFormat: $this->stringValue($scheduleCell, 'format'),
            placeholders: $placeholders,
            signatoryFields: $signatoryFields,
        );
    }

    private function worksheetExists(TemplateWorkbookInspection $inspection, string $worksheetName): bool
    {
        foreach ($inspection->worksheets as $worksheet) {
            if ($worksheet['name'] === $worksheetName) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $errors
     * @return array{column: int, row: int}|null
     */
    private function coordinateIndexes(string $cell, string $field, array &$errors): ?array
    {
        try {
            [$column, $row] = Coordinate::indexesFromString($cell);
        } catch (SpreadsheetException) {
            $errors[$field] = 'The referenced cell is outside the supported workbook range.';

            return null;
        }

        if ($row < 1) {
            $errors[$field] = 'The referenced cell row must be at least one.';

            return null;
        }

        return ['column' => $column, 'row' => $row];
    }

    /**
     * @param  array{column: int, row: int}|null  $start
     * @param  array{column: int, row: int}|null  $end
     * @param  array<string, string>  $errors
     */
    private function validateDayColumns(TemplateWorkbookMapping $mapping, ?array $start, ?array $end, array &$errors): void
    {
        $weekdays = [];
        $columns = [];

        foreach ($mapping->dayColumns as $index => $dayColumn) {
            $field = "day_columns.{$index}";

            if (in_array($dayColumn['weekday'], $weekdays, true)) {
                $errors["{$field}.weekday"] = 'Each weekday can be mapped only once.';
            }

            $weekdays[] = $dayColumn['weekday'];

            try {
                $columnIndex = Coordinate::columnIndexFromString($dayColumn['column']);
            } catch (SpreadsheetException) {
                $errors["{$field}.column"] = 'The mapped column is outside the supported workbook range.';

                continue;
            }

            if (in_array($columnIndex, $columns, true)) {
                $errors["{$field}.column"] = 'Each timetable column can be mapped only once.';
            }

            $columns[] = $columnIndex;

            if ($start !== null && $end !== null && ($columnIndex < $start['column'] || $columnIndex > $end['column'])) {
                $errors["{$field}.column"] = 'Mapped day columns must be inside the timetable area.';
            }
        }
    }

    /**
     * @param  array{column: int, row: int}|null  $start
     * @param  array{column: int, row: int}|null  $end
     * @param  array<string, string>  $errors
     */
    private function validateTimeRows(Organization $organization, TemplateWorkbookMapping $mapping, ?array $start, ?array $end, array &$errors): void
    {
        $rows = [];
        $previousStartMinute = null;

        foreach ($mapping->timeRows as $index => $timeRow) {
            $field = "time_rows.{$index}";

            if (in_array($timeRow['row'], $rows, true)) {
                $errors["{$field}.row"] = 'Each timetable row can be mapped only once.';
            }

            $rows[] = $timeRow['row'];

            if ($start !== null && $end !== null && ($timeRow['row'] < $start['row'] || $timeRow['row'] > $end['row'])) {
                $errors["{$field}.row"] = 'Mapped time rows must be inside the timetable area.';
            }

            if ($timeRow['start_minute'] % $organization->scheduling_granularity !== 0) {
                $errors["{$field}.start_minute"] = 'Mapped time rows must align with the organization scheduling granularity.';
            }

            if ($previousStartMinute !== null && $timeRow['start_minute'] <= $previousStartMinute) {
                $errors["{$field}.start_minute"] = 'Mapped time rows must be ordered by increasing start minute.';
            }

            $previousStartMinute = $timeRow['start_minute'];
        }
    }

    /**
     * @param  array{column: int, row: int}|null  $start
     * @param  array{column: int, row: int}|null  $end
     * @param  array<string, string>  $errors
     */
    private function validateFixedFields(TemplateWorkbookMapping $mapping, ?array $start, ?array $end, array &$errors): void
    {
        $keys = [];
        $roles = [];
        $cells = [];

        foreach ($mapping->placeholders as $index => $placeholder) {
            $field = "placeholders.{$index}";

            if (in_array($placeholder['key'], $keys, true)) {
                $errors["{$field}.key"] = 'Each placeholder key can be mapped only once.';
            }

            if (TemplatePlaceholder::tryFrom($placeholder['key']) === null) {
                $errors["{$field}.key"] = 'The placeholder key is not supported by the template catalog.';
            }

            $keys[] = $placeholder['key'];
            $this->validateFixedCell($placeholder['cell'], "{$field}.cell", $start, $end, $cells, $errors);
        }

        foreach ($mapping->signatoryFields as $index => $signatoryField) {
            $field = "signatory_fields.{$index}";

            if (in_array($signatoryField['role'], $roles, true)) {
                $errors["{$field}.role"] = 'Each signatory role can be mapped only once.';
            }

            $roles[] = $signatoryField['role'];
            $this->validateFixedCell($signatoryField['name_cell'], "{$field}.name_cell", $start, $end, $cells, $errors);

            if ($signatoryField['position_cell'] !== null) {
                $this->validateFixedCell($signatoryField['position_cell'], "{$field}.position_cell", $start, $end, $cells, $errors);
            }

            if ($signatoryField['signature_cell'] !== null) {
                $this->validateFixedCell($signatoryField['signature_cell'], "{$field}.signature_cell", $start, $end, $cells, $errors);
            }
        }
    }

    /**
     * @param  array{column: int, row: int}|null  $start
     * @param  array{column: int, row: int}|null  $end
     * @param  array<string, true>  $cells
     * @param  array<string, string>  $errors
     */
    private function validateFixedCell(string $cell, string $field, ?array $start, ?array $end, array &$cells, array &$errors): void
    {
        $indexes = $this->coordinateIndexes($cell, $field, $errors);

        if ($indexes === null) {
            return;
        }

        if (isset($cells[$cell])) {
            $errors[$field] = 'Each fixed output cell can be mapped only once.';
        }

        $cells[$cell] = true;

        if ($start !== null
            && $end !== null
            && $indexes['column'] >= $start['column']
            && $indexes['column'] <= $end['column']
            && $indexes['row'] >= $start['row']
            && $indexes['row'] <= $end['row']) {
            $errors[$field] = 'Fixed output cells cannot overlap the timetable area.';
        }
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, mixed>
     */
    private function arrayValue(array $values, string $key): array
    {
        $value = $values[$key] ?? null;

        return $this->associativeArray($value, "Validated mapping {$key} must be an array.");
    }

    /** @param array<mixed> $values */
    private function stringValue(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        if (! is_string($value)) {
            throw new LogicException("Validated mapping {$key} must be a string.");
        }

        return $value;
    }

    /** @param array<mixed> $values */
    private function integerValue(array $values, string $key): int
    {
        $value = $values[$key] ?? null;

        if (! is_int($value)) {
            throw new LogicException("Validated mapping {$key} must be an integer.");
        }

        return $value;
    }

    /** @param array<mixed> $values */
    private function nullableCell(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new LogicException("Validated mapping {$key} must be a string or null.");
        }

        return Str::upper($value);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<array<string, mixed>>
     */
    private function listValue(array $values, string $key): array
    {
        $value = $values[$key] ?? [];

        if (! is_array($value)) {
            throw new LogicException("Validated mapping {$key} must be a list.");
        }

        $list = [];

        foreach ($value as $item) {
            $list[] = $this->associativeArray($item, "Validated mapping {$key} must contain arrays.");
        }

        return $list;
    }

    /**
     * @return array<string, mixed>
     */
    private function associativeArray(mixed $value, string $message): array
    {
        if (! is_array($value)) {
            throw new LogicException($message);
        }

        $associativeArray = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new LogicException($message);
            }

            $associativeArray[$key] = $item;
        }

        return $associativeArray;
    }
}
