<?php

use App\Actions\InspectTemplateWorkbook;
use App\Actions\StoreTemplateWorkbook;
use App\Jobs\ScanFileAsset;
use App\Models\FileAsset;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use ZipArchive;

beforeEach(function (): void {
    Storage::fake(FileAsset::PRIVATE_DISK);
    $this->owner = User::factory()->withOwnedOrganization()->create();
    $this->organization = $this->owner->currentOrganization;
    grantCustomExcelTemplatesEntitlement($this->organization);
});

test('authorized schedulers store one private immutable workbook asset per checksum', function (): void {
    $workbook = templateWorkbookUpload();
    Queue::fake();

    $first = app(StoreTemplateWorkbook::class)->handle($this->organization, $this->owner, $workbook);
    $second = app(StoreTemplateWorkbook::class)->handle($this->organization, $this->owner, $workbook);

    expect($first->is($second))->toBeTrue()
        ->and($first->organization_id)->toBe($this->organization->id)
        ->and($first->disk)->toBe(FileAsset::PRIVATE_DISK)
        ->and($first->scan_status)->toBe(FileAsset::ScanPending)
        ->and($first->checksum)->toHaveLength(64)
        ->and(DB::table('file_assets')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'template_workbook.uploaded')->count())->toBe(1);

    Storage::disk(FileAsset::PRIVATE_DISK)->assertExists($first->path);
    Queue::assertPushedOn('security', ScanFileAsset::class, function (ScanFileAsset $job) use ($first): bool {
        return $job->organizationPublicId === $this->organization->public_id
            && $job->fileAssetPublicId === $first->public_id;
    });

    expect(fn () => $first->update(['path' => 'organizations/replaced.xlsx']))
        ->toThrow(LogicException::class);
});

test('template workbook storage requires the custom templates capability', function (): void {
    $actor = User::factory()->withOwnedOrganization()->create();
    $organization = $actor->currentOrganization;

    expect(fn () => app(StoreTemplateWorkbook::class)->handle(
        $organization,
        $actor,
        UploadedFile::fake()->create(
            'fall-timetable.xlsx',
            12,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ),
    ))->toThrow(AuthorizationException::class);

    Storage::disk(FileAsset::PRIVATE_DISK)->assertDirectoryEmpty('/');
});

test('template workbook storage rejects unapproved workbook types before writing an asset', function (): void {
    expect(fn () => app(StoreTemplateWorkbook::class)->handle(
        $this->organization,
        $this->owner,
        UploadedFile::fake()->create('untrusted-template.xlsm', 12, 'application/vnd.ms-excel.sheet.macroEnabled.12'),
    ))->toThrow(ValidationException::class);

    expect(FileAsset::query()->count())->toBe(0);

    Storage::disk(FileAsset::PRIVATE_DISK)->assertDirectoryEmpty('/');
});

test('template workbook storage rejects macros and compression bombs before writing an asset', function (): void {
    $macroWorkbook = templateWorkbookUploadWithEntry('xl/vbaProject.bin', 'macro payload');

    expect(fn () => app(StoreTemplateWorkbook::class)->handle(
        $this->organization,
        $this->owner,
        $macroWorkbook,
    ))->toThrow(ValidationException::class);

    $oversizedWorkbook = templateWorkbookUploadWithEntry(
        'xl/media/over-compressed.xml',
        str_repeat('A', 9 * 1_024 * 1_024),
    );

    try {
        app(StoreTemplateWorkbook::class)->handle($this->organization, $this->owner, $oversizedWorkbook);
        $this->fail('The oversized workbook should be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['workbook'][0])->toContain('expands beyond');
    }

    $compressedWorkbook = templateWorkbookUploadWithEntry(
        'xl/media/high-ratio.xml',
        str_repeat('A', 1 * 1_024 * 1_024),
    );

    try {
        app(StoreTemplateWorkbook::class)->handle($this->organization, $this->owner, $compressedWorkbook);
        $this->fail('The compressed workbook should be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['workbook'][0])->toContain('compression ratio');
    }

    expect(FileAsset::query()->count())->toBe(0);
    Storage::disk(FileAsset::PRIVATE_DISK)->assertDirectoryEmpty('/');
});

test('authorized schedulers inspect clean workbooks without evaluating formulas', function (): void {
    $asset = storeCleanTemplateWorkbook($this->organization);

    $inspection = app(InspectTemplateWorkbook::class)->handle($this->organization, $this->owner, $asset);
    $worksheet = $inspection->worksheets[0];

    expect($inspection->worksheets)->toHaveCount(2)
        ->and($worksheet['name'])->toBe('Schedule')
        ->and($worksheet['dimensions'])->toBe([
            'columns' => 3,
            'range' => 'A1:C2',
            'rows' => 2,
        ])
        ->and($worksheet['merged_cells'])->toBe(['A1:C1'])
        ->and($worksheet['preview']['columns'])->toBe(['A', 'B', 'C'])
        ->and($worksheet['preview']['rows'][0]['cells'][0])->toBe([
            'coordinate' => 'A1',
            'is_formula' => false,
            'value' => 'Weekly timetable',
        ])
        ->and($worksheet['preview']['rows'][1]['cells'][2])->toBe([
            'coordinate' => 'C2',
            'is_formula' => true,
            'value' => null,
        ])
        ->and($inspection->worksheets[1]['name'])->toBe('Notes');
});

test('template workbook inspection refuses unscanned workbooks', function (): void {
    $asset = storeCleanTemplateWorkbook($this->organization);
    $asset->scan_status = FileAsset::ScanPending;
    $asset->save();

    expect(fn () => app(InspectTemplateWorkbook::class)->handle($this->organization, $this->owner, $asset))
        ->toThrow(ValidationException::class);
});

function storeCleanTemplateWorkbook(Organization $organization): FileAsset
{
    $contents = templateWorkbookContents();
    $path = 'organizations/'.$organization->public_id.'/workbooks/inspection.xlsx';
    Storage::disk(FileAsset::PRIVATE_DISK)->put($path, $contents);

    return FileAsset::factory()->create([
        'organization_id' => $organization->id,
        'path' => $path,
        'checksum' => hash('sha256', $contents),
        'scan_status' => FileAsset::ScanClean,
    ]);
}

function templateWorkbookUpload(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('fall-timetable.xlsx', templateWorkbookContents());
}

function templateWorkbookUploadWithEntry(string $entryName, string $entryContents): UploadedFile
{
    $temporaryPath = tempnam(sys_get_temp_dir(), 'tala-template-archive-');

    if ($temporaryPath === false) {
        throw new RuntimeException('A temporary workbook archive could not be created.');
    }

    try {
        file_put_contents($temporaryPath, templateWorkbookContents());
        $archive = new ZipArchive;

        if ($archive->open($temporaryPath) !== true) {
            throw new RuntimeException('The test workbook archive could not be opened.');
        }

        try {
            if (! $archive->addFromString($entryName, $entryContents)) {
                throw new RuntimeException('The test workbook archive entry could not be added.');
            }
        } finally {
            $archive->close();
        }

        $contents = file_get_contents($temporaryPath);
    } finally {
        unlink($temporaryPath);
    }

    if ($contents === false) {
        throw new RuntimeException('The test workbook archive could not be read.');
    }

    return UploadedFile::fake()->createWithContent('untrusted-template.xlsx', $contents);
}

function templateWorkbookContents(): string
{
    $spreadsheet = new Spreadsheet;
    $schedule = $spreadsheet->getActiveSheet();
    $schedule->setTitle('Schedule');
    $schedule->mergeCells('A1:C1');
    $schedule->setCellValue('A1', 'Weekly timetable');
    $schedule->setCellValue('A2', 'Subject');
    $schedule->setCellValue('B2', 'Room');
    $schedule->setCellValue('C2', '=SUM(1, 2)');
    $spreadsheet->createSheet()->setTitle('Notes')->setCellValue('A1', 'Map only approved cells.');

    $temporaryPath = tempnam(sys_get_temp_dir(), 'tala-template-');

    if ($temporaryPath === false) {
        throw new RuntimeException('A temporary workbook could not be created.');
    }

    try {
        (new Xlsx($spreadsheet))->save($temporaryPath);
        $contents = file_get_contents($temporaryPath);
    } finally {
        $spreadsheet->disconnectWorksheets();
        unlink($temporaryPath);
    }

    if ($contents === false) {
        throw new RuntimeException('The test workbook could not be read.');
    }

    return $contents;
}
