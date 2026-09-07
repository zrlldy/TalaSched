<?php

namespace App\Actions;

use App\Data\TemplateWorkbookInspection;
use App\Models\FileAsset;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

class InspectTemplateWorkbook
{
    private const int MaximumMergedCells = 100;

    private const int MaximumPreviewColumns = 20;

    private const int MaximumPreviewRows = 25;

    public function __construct(
        private TenantContext $tenantContext,
        private ValidateTemplateWorkbookArchive $archiveValidator,
    ) {}

    public function handle(Organization $organization, User $actor, FileAsset $fileAsset): TemplateWorkbookInspection
    {
        return $this->tenantContext->run($organization, function () use ($organization, $actor, $fileAsset): TemplateWorkbookInspection {
            $asset = FileAsset::query()
                ->whereKey($fileAsset->getKey())
                ->where('organization_id', $organization->getKey())
                ->firstOrFail();

            Gate::forUser($actor)->authorize('view', $asset);

            if ($asset->scan_status !== FileAsset::ScanClean) {
                throw ValidationException::withMessages([
                    'workbook' => 'The workbook must complete security scanning before inspection.',
                ]);
            }

            return $this->inspect($asset);
        });
    }

    private function inspect(FileAsset $asset): TemplateWorkbookInspection
    {
        $source = Storage::disk($asset->disk)->readStream($asset->path);
        $temporaryFile = tmpfile();

        if (! is_resource($source) || ! is_resource($temporaryFile)) {
            if (is_resource($source)) {
                fclose($source);
            }

            if (is_resource($temporaryFile)) {
                fclose($temporaryFile);
            }

            throw new RuntimeException('The workbook could not be opened for inspection.');
        }

        try {
            if (stream_copy_to_stream($source, $temporaryFile) === false) {
                throw new RuntimeException('The workbook could not be copied for inspection.');
            }

            $metadata = stream_get_meta_data($temporaryFile);
            $temporaryPath = $metadata['uri'] ?? null;

            if (! is_string($temporaryPath) || $temporaryPath === '') {
                throw new RuntimeException('The workbook inspection file could not be prepared.');
            }

            try {
                $this->archiveValidator->validate($temporaryPath);
                $reader = IOFactory::createReaderForFile($temporaryPath);
                $reader->setIncludeCharts(false);
                $reader->setReadDataOnly(false);
                $reader->setReadEmptyCells(false);
                $spreadsheet = $reader->load($temporaryPath);
            } catch (LogicException|SpreadsheetException $exception) {
                report($exception);

                throw ValidationException::withMessages([
                    'workbook' => 'The workbook could not be inspected.',
                ]);
            }

            try {
                $worksheets = [];

                foreach ($spreadsheet->getAllSheets() as $worksheet) {
                    $worksheets[] = $this->worksheetInspection($worksheet);
                }

                return new TemplateWorkbookInspection(
                    worksheets: $worksheets,
                );
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        } finally {
            fclose($source);
            fclose($temporaryFile);
        }
    }

    /**
     * @return array{
     *     dimensions: array{columns: int, range: string, rows: int},
     *     merged_cell_count: int,
     *     merged_cells: list<string>,
     *     merged_cells_truncated: bool,
     *     name: string,
     *     preview: array{
     *         columns: list<string>,
     *         rows: list<array{
     *             cells: list<array{coordinate: string, is_formula: bool, value: bool|float|int|string|null}>,
     *             row: int
     *         }>,
     *         truncated: bool
     *     }
     * }
     */
    private function worksheetInspection(Worksheet $worksheet): array
    {
        $highestColumn = $worksheet->getHighestDataColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);
        $highestRow = $worksheet->getHighestDataRow();
        $mergedCells = array_values($worksheet->getMergeCells());
        $previewColumnCount = min($highestColumnIndex, self::MaximumPreviewColumns);
        $previewRowCount = min($highestRow, self::MaximumPreviewRows);

        $columns = [];

        for ($column = 1; $column <= $previewColumnCount; $column++) {
            $columns[] = Coordinate::stringFromColumnIndex($column);
        }

        $rows = [];

        for ($row = 1; $row <= $previewRowCount; $row++) {
            $cells = [];

            foreach ($columns as $column) {
                $coordinate = $column.$row;
                $cell = $worksheet->getCell($coordinate);
                $isFormula = $cell->isFormula();

                $cells[] = [
                    'coordinate' => $coordinate,
                    'is_formula' => $isFormula,
                    'value' => $isFormula ? null : $this->previewValue($cell->getValue()),
                ];
            }

            $rows[] = [
                'cells' => $cells,
                'row' => $row,
            ];
        }

        return [
            'dimensions' => [
                'columns' => $highestColumnIndex,
                'range' => $worksheet->calculateWorksheetDataDimension(),
                'rows' => $highestRow,
            ],
            'merged_cell_count' => count($mergedCells),
            'merged_cells' => array_slice($mergedCells, 0, self::MaximumMergedCells),
            'merged_cells_truncated' => count($mergedCells) > self::MaximumMergedCells,
            'name' => $worksheet->getTitle(),
            'preview' => [
                'columns' => $columns,
                'rows' => $rows,
                'truncated' => $highestColumnIndex > self::MaximumPreviewColumns || $highestRow > self::MaximumPreviewRows,
            ],
        ];
    }

    private function previewValue(mixed $value): string|int|float|bool|null
    {
        if ($value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        return (string) $value;
    }
}
