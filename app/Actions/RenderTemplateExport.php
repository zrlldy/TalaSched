<?php

namespace App\Actions;

use App\Audit\AuditLogger;
use App\Enums\ExportRunStatus;
use App\Models\ExportRun;
use App\Models\FileAsset;
use App\Models\Organization;
use App\Models\ScheduleEntry;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

class RenderTemplateExport
{
    public function __construct(
        private TenantContext $tenantContext,
        private ValidateTemplateWorkbookArchive $archiveValidator,
        private ResolveTemplatePlaceholders $placeholders,
        private ResolveTemplateSignatories $signatories,
        private AuditLogger $audit,
    ) {}

    public function handle(string $exportRunPublicId): void
    {
        $organization = $this->tenantContext->organization();

        if (! $organization instanceof Organization) {
            throw new LogicException('Template export rendering requires a tenant context.');
        }

        $run = $this->start($organization, $exportRunPublicId);

        if ($run === null) {
            return;
        }

        $outputPath = null;

        try {
            [$temporaryFile, $spreadsheet] = $this->renderWorkbook($organization, $run);
            $output = $this->storeWorkbook($organization, $run, $temporaryFile);
            $outputPath = $output['path'];
            $spreadsheet->disconnectWorksheets();

            $this->complete($organization, $run, $output);
        } catch (Throwable $exception) {
            if ($outputPath !== null) {
                Storage::disk(FileAsset::PRIVATE_DISK)->delete($outputPath);
            }

            throw $exception;
        } finally {
            if (isset($temporaryFile) && is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
    }

    public function fail(string $exportRunPublicId, ?Throwable $exception): void
    {
        $organization = $this->tenantContext->organization();

        if (! $organization instanceof Organization) {
            return;
        }

        $run = ExportRun::query()
            ->where('organization_id', $organization->getKey())
            ->where('public_id', $exportRunPublicId)
            ->first();

        if (! $run instanceof ExportRun || $run->status->isTerminal()) {
            return;
        }

        $message = Str::limit($exception?->getMessage() ?? 'The export worker stopped before completing the workbook.', 65_535, '');

        $run->update([
            'status' => ExportRunStatus::Failed,
            'error' => $message,
            'completed_at' => now(),
        ]);

        $this->audit->record('template_export.failed', $organization, subject: $run, after: [
            'error' => $message,
        ]);
    }

    private function start(Organization $organization, string $exportRunPublicId): ?ExportRun
    {
        return DB::transaction(function () use ($organization, $exportRunPublicId): ?ExportRun {
            $run = ExportRun::query()
                ->with(['templateVersion.sourceAsset', 'timetableVersion'])
                ->where('organization_id', $organization->getKey())
                ->where('public_id', $exportRunPublicId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($run->status->isTerminal()) {
                return null;
            }

            if ($run->templateVersion->sourceAsset->scan_status !== FileAsset::ScanClean) {
                throw new LogicException('The template source asset is no longer cleared for workbook processing.');
            }

            if ($run->status === ExportRunStatus::Pending) {
                $run->update(['status' => ExportRunStatus::Running]);
            }

            return $run;
        });
    }

    /**
     * @return array{0: string, 1: Spreadsheet}
     */
    private function renderWorkbook(Organization $organization, ExportRun $run): array
    {
        $sourceAsset = $run->templateVersion->sourceAsset;
        $sourceStream = Storage::disk($sourceAsset->disk)->readStream($sourceAsset->path);

        if (! is_resource($sourceStream)) {
            throw new LogicException('The source workbook cannot be read from private storage.');
        }

        $sourcePath = tempnam(sys_get_temp_dir(), 'talasched-source-');

        if ($sourcePath === false) {
            fclose($sourceStream);

            throw new LogicException('A temporary source workbook could not be opened.');
        }

        $sourceFile = fopen($sourcePath, 'wb');

        if ($sourceFile === false) {
            fclose($sourceStream);
            unlink($sourcePath);

            throw new LogicException('A temporary source workbook could not be written.');
        }

        try {
            if (stream_copy_to_stream($sourceStream, $sourceFile) === false) {
                throw new LogicException('The source workbook could not be copied from private storage.');
            }
        } finally {
            fclose($sourceStream);
            fclose($sourceFile);
        }

        try {
            $this->archiveValidator->validate($sourcePath);
            $reader = IOFactory::createReaderForFile($sourcePath);
            $reader->setIncludeCharts(true);
            $reader->setReadDataOnly(false);
            $spreadsheet = $reader->load($sourcePath);

            $mapping = $run->templateVersion->mapping;
            $worksheet = $spreadsheet->getSheetByName($this->string($mapping, 'worksheet'));

            if (! $worksheet instanceof Worksheet) {
                $spreadsheet->disconnectWorksheets();

                throw new LogicException('The mapped worksheet is not present in the source workbook.');
            }

            $temporarySignatureFiles = [];

            try {
                $this->renderPlaceholders($worksheet, $mapping, $this->placeholders->resolve($organization, $run->timetableVersion, now()));
                $this->renderSignatories(
                    $worksheet,
                    $mapping,
                    $this->signatories->resolve($organization, $run->timetableVersion),
                    $temporarySignatureFiles,
                );
                $this->renderSchedule($worksheet, $mapping, $organization, $run);

                $temporaryFile = tempnam(sys_get_temp_dir(), 'talasched-export-');

                if ($temporaryFile === false) {
                    $spreadsheet->disconnectWorksheets();

                    throw new LogicException('A temporary export workbook could not be opened.');
                }

                $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
                $writer->setIncludeCharts(true);
                $writer->setPreCalculateFormulas(false);
                $writer->save($temporaryFile);

                return [$temporaryFile, $spreadsheet];
            } finally {
                foreach ($temporarySignatureFiles as $temporarySignatureFile) {
                    if (is_file($temporarySignatureFile)) {
                        unlink($temporarySignatureFile);
                    }
                }
            }
        } finally {
            if (is_file($sourcePath)) {
                unlink($sourcePath);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @param  array<string, string>  $values
     */
    private function renderPlaceholders(Worksheet $worksheet, array $mapping, array $values): void
    {
        foreach ($this->list($mapping, 'placeholders') as $placeholder) {
            $key = $this->string($placeholder, 'key');
            $this->writeCell($worksheet, $this->string($placeholder, 'cell'), $values[$key] ?? '');
        }
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @param  array<string, array{name: string, position: string, signature_checksum: string|null, signature_disk: string|null, signature_path: string|null}>  $signatories
     * @param  list<string>  $temporarySignatureFiles
     */
    private function renderSignatories(Worksheet $worksheet, array $mapping, array $signatories, array &$temporarySignatureFiles): void
    {
        foreach ($this->list($mapping, 'signatory_fields') as $field) {
            $signatory = $signatories[$this->string($field, 'role')] ?? null;
            $name = $signatory['name'] ?? '';
            $position = $signatory['position'] ?? '';

            $this->writeCell($worksheet, $this->string($field, 'name_cell'), $name);

            $positionCell = $field['position_cell'] ?? null;

            if (is_string($positionCell)) {
                $this->writeCell($worksheet, $positionCell, $position);
            }

            $signatureCell = $field['signature_cell'] ?? null;

            if (! is_string($signatureCell) || $signatory === null || $signatory['signature_path'] === null) {
                continue;
            }

            $temporarySignatureFile = $this->copySignatureSnapshot($signatory);
            $temporarySignatureFiles[] = $temporarySignatureFile;
            $drawing = new Drawing;
            $drawing->setName('Approved signatory');
            $drawing->setDescription('Historical approval signature');
            $drawing->setPath($temporarySignatureFile);
            $drawing->setHeight(72);
            $drawing->setCoordinates($signatureCell);
            $drawing->setWorksheet($worksheet);
        }
    }

    /**
     * @param  array{name: string, position: string, signature_checksum: string|null, signature_disk: string|null, signature_path: string|null}  $signatory
     */
    private function copySignatureSnapshot(array $signatory): string
    {
        $disk = $signatory['signature_disk'];
        $path = $signatory['signature_path'];
        $checksum = $signatory['signature_checksum'];

        if (! is_string($disk) || ! is_string($path) || ! is_string($checksum)) {
            throw new LogicException('The approved signatory snapshot is missing its private signature asset.');
        }

        $source = Storage::disk($disk)->readStream($path);

        if (! is_resource($source)) {
            throw new LogicException('The approved signatory signature cannot be read from private storage.');
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'talasched-signature-');

        if ($temporaryFile === false) {
            fclose($source);

            throw new LogicException('A temporary signatory signature could not be opened.');
        }

        $destination = fopen($temporaryFile, 'wb');

        if ($destination === false) {
            fclose($source);
            unlink($temporaryFile);

            throw new LogicException('A temporary signatory signature could not be written.');
        }

        $copied = false;

        try {
            if (stream_copy_to_stream($source, $destination) === false) {
                throw new LogicException('The approved signatory signature could not be copied from private storage.');
            }

            if (! fflush($destination)) {
                throw new LogicException('The approved signatory signature could not be finalized in temporary storage.');
            }

            $actualChecksum = hash_file('sha256', $temporaryFile);

            if (! is_string($actualChecksum) || ! hash_equals($checksum, $actualChecksum)) {
                throw new LogicException('The approved signatory signature does not match its recorded checksum.');
            }

            $copied = true;
        } finally {
            fclose($source);
            fclose($destination);

            if (! $copied && is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }

        $extension = Str::lower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension !== 'webp') {
            return $temporaryFile;
        }

        if (! function_exists('imagecreatefromwebp')) {
            unlink($temporaryFile);

            throw new LogicException('WebP signatures require GD WebP support for workbook export.');
        }

        $image = imagecreatefromwebp($temporaryFile);

        if ($image === false) {
            unlink($temporaryFile);

            throw new LogicException('The approved signatory WebP signature could not be decoded.');
        }

        $pngFile = $temporaryFile.'.png';
        $converted = imagepng($image, $pngFile);
        imagedestroy($image);
        unlink($temporaryFile);

        if (! $converted) {
            if (is_file($pngFile)) {
                unlink($pngFile);
            }

            throw new LogicException('The approved signatory WebP signature could not be converted for export.');
        }

        return $pngFile;
    }

    /** @param array<string, mixed> $mapping */
    private function renderSchedule(Worksheet $worksheet, array $mapping, Organization $organization, ExportRun $run): void
    {
        $dayColumns = [];

        foreach ($this->list($mapping, 'day_columns') as $column) {
            $dayColumns[$this->integer($column, 'weekday')] = $this->string($column, 'column');
        }

        $timeRows = [];

        foreach ($this->list($mapping, 'time_rows') as $timeRow) {
            $timeRows[$this->integer($timeRow, 'start_minute')] = $this->integer($timeRow, 'row');
        }

        $format = $this->string($this->array($mapping, 'schedule_cell'), 'format');
        $cellValues = [];

        foreach ($this->entries($organization, $run) as $entry) {
            $column = $dayColumns[$entry->weekday] ?? null;

            if ($column === null) {
                throw new LogicException("The workbook mapping has no column for weekday {$entry->weekday}.");
            }

            if (! isset($timeRows[$entry->starts_at_minute])) {
                throw new LogicException('Each exported session must start on a mapped template slot.');
            }

            foreach ($timeRows as $startMinute => $row) {
                if ($startMinute >= $entry->starts_at_minute && $startMinute < $entry->ends_at_minute) {
                    $cellValues[$column.$row][] = $this->formatEntry($entry, $format);
                }
            }
        }

        foreach ($cellValues as $coordinate => $values) {
            $this->writeCell($worksheet, $coordinate, implode(PHP_EOL.PHP_EOL, $values));
        }
    }

    /** @return Collection<int, ScheduleEntry> */
    private function entries(Organization $organization, ExportRun $run): Collection
    {
        return ScheduleEntry::query()
            ->with([
                'offeringComponent.offering.studentGroup',
                'offeringComponent.offering.subject',
                'resources.resource',
            ])
            ->where('organization_id', $organization->getKey())
            ->where('timetable_version_id', $run->timetable_version_id)
            ->orderBy('weekday')
            ->orderBy('starts_at_minute')
            ->orderBy('ends_at_minute')
            ->orderBy('public_id')
            ->get();
    }

    private function formatEntry(ScheduleEntry $entry, string $format): string
    {
        $offering = $entry->offeringComponent->offering;
        $tokens = [
            '{{component_name}}' => $entry->offeringComponent->name,
            '{{end_time}}' => $this->time($entry->ends_at_minute),
            '{{group_code}}' => $offering->studentGroup->code,
            '{{group_name}}' => $offering->studentGroup->name,
            '{{instructors}}' => $this->resourceNames($entry, 'faculty'),
            '{{rooms}}' => $this->resourceNames($entry, 'room'),
            '{{start_time}}' => $this->time($entry->starts_at_minute),
            '{{subject_code}}' => $offering->subject->code,
            '{{subject_name}}' => $offering->subject->name,
        ];

        preg_match_all('/{{[a-z_]+}}/', $format, $matches);

        foreach ($matches[0] as $token) {
            if (! array_key_exists($token, $tokens)) {
                throw new LogicException("The schedule cell format contains unsupported token {$token}.");
            }
        }

        return strtr($format, $tokens);
    }

    private function resourceNames(ScheduleEntry $entry, string $type): string
    {
        return $entry->resources
            ->filter(fn ($assignment): bool => $assignment->resource->type->value === $type)
            ->map(fn ($assignment): string => $assignment->resource->name)
            ->sort()
            ->join(', ');
    }

    private function writeCell(Worksheet $worksheet, string $coordinate, string $value): void
    {
        $cell = $worksheet->getCell($coordinate);

        if ($cell->isFormula()) {
            throw new LogicException("Mapped output cell {$coordinate} contains a formula.");
        }

        if ($cell->isInMergeRange() && ! $cell->isMergeRangeValueCell()) {
            throw new LogicException("Mapped output cell {$coordinate} is not the top-left cell of its merged range.");
        }

        $cell->setValue($value);
    }

    /** @return array{checksum: string, path: string, size: int} */
    private function storeWorkbook(Organization $organization, ExportRun $run, string $temporaryFile): array
    {
        $stream = fopen($temporaryFile, 'rb');

        if ($stream === false) {
            throw new LogicException('The completed export workbook cannot be read.');
        }

        $path = 'organizations/'.Str::lower($organization->public_id).'/exports/'.$run->public_id.'.xlsx';
        $checksum = hash_file('sha256', $temporaryFile);

        if ($checksum === false) {
            fclose($stream);

            throw new LogicException('The completed export checksum could not be calculated.');
        }

        $size = filesize($temporaryFile);

        if (! is_int($size)) {
            fclose($stream);

            throw new LogicException('The completed export size could not be calculated.');
        }

        try {
            if (! Storage::disk(FileAsset::PRIVATE_DISK)->put($path, $stream)) {
                throw new LogicException('The completed export workbook could not be written to private storage.');
            }
        } finally {
            fclose($stream);
        }

        return [
            'checksum' => $checksum,
            'path' => $path,
            'size' => $size,
        ];
    }

    /** @param array{checksum: string, path: string, size: int} $output */
    private function complete(Organization $organization, ExportRun $run, array $output): void
    {
        DB::transaction(function () use ($organization, $run, $output): void {
            $lockedRun = ExportRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();
            $asset = FileAsset::query()
                ->where('organization_id', $organization->getKey())
                ->where('disk', FileAsset::PRIVATE_DISK)
                ->where('checksum', $output['checksum'])
                ->first();

            if (! $asset instanceof FileAsset) {
                $asset = FileAsset::query()->create([
                    'organization_id' => $organization->getKey(),
                    'disk' => FileAsset::PRIVATE_DISK,
                    'path' => $output['path'],
                    'original_name' => 'timetable-'.$lockedRun->purpose->value.'-'.$lockedRun->public_id.'.xlsx',
                    'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'size' => $output['size'],
                    'checksum' => $output['checksum'],
                    'scan_status' => FileAsset::ScanClean,
                ]);
            } else {
                Storage::disk(FileAsset::PRIVATE_DISK)->delete($output['path']);
            }

            $lockedRun->update([
                'artifact_file_asset_id' => $asset->getKey(),
                'status' => ExportRunStatus::Completed,
                'completed_at' => now(),
            ]);

            $this->audit->record('template_export.completed', $organization, subject: $lockedRun, after: [
                'artifact' => $asset->public_id,
                'purpose' => $lockedRun->purpose->value,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function array(array $values, string $key): array
    {
        $value = $values[$key] ?? null;

        if (! is_array($value)) {
            throw new LogicException("Template mapping {$key} must be an array.");
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function string(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        if (! is_string($value)) {
            throw new LogicException("Template mapping {$key} must be a string.");
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function integer(array $values, string $key): int
    {
        $value = $values[$key] ?? null;

        if (! is_int($value)) {
            throw new LogicException("Template mapping {$key} must be an integer.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<array<string, mixed>>
     */
    private function list(array $values, string $key): array
    {
        $value = $values[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            throw new LogicException("Template mapping {$key} must be a list.");
        }

        foreach ($value as $item) {
            if (! is_array($item)) {
                throw new LogicException("Template mapping {$key} must contain arrays.");
            }
        }

        return $value;
    }

    private function time(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
