<?php

use App\Actions\QueueTemplateExport;
use App\Actions\RenderTemplateExport;
use App\Enums\ExportRunPurpose;
use App\Enums\ExportRunStatus;
use App\Jobs\GenerateTemplateExport;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\ExcelTemplate;
use App\Models\ExcelTemplateVersion;
use App\Models\FileAsset;
use App\Models\Organization;
use App\Models\ScheduleEntry;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use LogicException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;
use ZipArchive;

beforeEach(function (): void {
    Storage::fake(FileAsset::PRIVATE_DISK);
    $this->owner = User::factory()->withOwnedOrganization()->create();
    $this->organization = $this->owner->currentOrganization;
    $this->organization->update(['timezone' => 'Asia/Manila']);
    grantCustomExcelTemplatesEntitlement($this->organization);
});

test('queues an encrypted preview job after creating a tenant-bound run', function (): void {
    [$timetableVersion, $templateVersion] = exportInputs($this->organization);
    Queue::fake();

    $run = app(QueueTemplateExport::class)->handle(
        $this->organization,
        $this->owner,
        $timetableVersion,
        $templateVersion,
        ExportRunPurpose::Preview,
    );

    expect($run->status)->toBe(ExportRunStatus::Pending)
        ->and($run->purpose)->toBe(ExportRunPurpose::Preview)
        ->and($run->organization_id)->toBe($this->organization->id);

    Queue::assertPushedOn('workbooks', GenerateTemplateExport::class, function (GenerateTemplateExport $job) use ($run): bool {
        return $job->organizationPublicId === $this->organization->public_id
            && $job->exportRunPublicId === $run->public_id;
    });
});

test('renders a private workbook while preserving unmapped formulas and merged cells', function (): void {
    [$timetableVersion, $templateVersion] = exportInputs($this->organization);
    $entry = ScheduleEntry::factory()->create([
        'organization_id' => $this->organization->id,
        'timetable_version_id' => $timetableVersion->id,
        'weekday' => 1,
        'starts_at_minute' => 480,
        'ends_at_minute' => 540,
    ]);
    $collidingEntry = ScheduleEntry::factory()->create([
        'organization_id' => $this->organization->id,
        'timetable_version_id' => $timetableVersion->id,
        'weekday' => 1,
        'starts_at_minute' => 480,
        'ends_at_minute' => 540,
    ]);
    $run = app(QueueTemplateExport::class)->handle(
        $this->organization,
        $this->owner,
        $timetableVersion,
        $templateVersion,
        ExportRunPurpose::Preview,
    );

    app(TenantContext::class)->run($this->organization, function () use ($run): void {
        app(RenderTemplateExport::class)->handle($run->public_id);
    });

    $run->refresh()->load('artifact');

    expect($run->status)->toBe(ExportRunStatus::Completed)
        ->and($run->artifact)->not->toBeNull();

    Storage::disk(FileAsset::PRIVATE_DISK)->assertExists($run->artifact->path);

    $temporaryFile = tempnam(sys_get_temp_dir(), 'talasched-export-test-');
    file_put_contents($temporaryFile, Storage::disk(FileAsset::PRIVATE_DISK)->get($run->artifact->path));
    $spreadsheet = IOFactory::load($temporaryFile);
    $worksheet = $spreadsheet->getSheetByName('Schedule');

    expect($worksheet->getCell('A1')->getValue())->toBe($this->organization->name)
        ->and($worksheet->getCell('B3')->getValue())->toContain($entry->offeringComponent->offering->subject->code)
        ->and($worksheet->getCell('B3')->getValue())->toContain($collidingEntry->offeringComponent->offering->subject->code)
        ->and($worksheet->getCell('B3')->getValue())->toContain(PHP_EOL.PHP_EOL)
        ->and($worksheet->getCell('B4')->getValue())->toBe($worksheet->getCell('B3')->getValue())
        ->and($worksheet->getCell('F1')->getValue())->toBe('=1+1')
        ->and($worksheet->getMergeCells())->toHaveKey('A1:A2');

    $spreadsheet->disconnectWorksheets();
    unlink($temporaryFile);
});

test('marks a run failed when a mapped output cell contains a formula', function (): void {
    [$timetableVersion, $templateVersion] = exportInputs($this->organization, [
        'key' => 'organization_name',
        'cell' => 'F1',
    ]);
    $run = app(QueueTemplateExport::class)->handle(
        $this->organization,
        $this->owner,
        $timetableVersion,
        $templateVersion,
        ExportRunPurpose::Preview,
    );

    $job = new GenerateTemplateExport($this->organization->public_id, $run->public_id);

    try {
        app(TenantContext::class)->run(
            $this->organization,
            fn (): mixed => $job->handle(app(RenderTemplateExport::class)),
        );
    } catch (Throwable $exception) {
        expect($exception::class)->toBe(LogicException::class, $exception->getMessage());
    }

    expect($run->refresh()->status)->toBe(ExportRunStatus::Failed)
        ->and($run->error)->toContain('contains a formula');
});

test('rejects source workbooks that contain external links before rendering', function (): void {
    [$timetableVersion, $templateVersion] = exportInputs($this->organization);
    $sourceAsset = $templateVersion->sourceAsset;
    $temporaryFile = tempnam(sys_get_temp_dir(), 'talasched-external-link-test-');
    file_put_contents($temporaryFile, Storage::disk(FileAsset::PRIVATE_DISK)->get($sourceAsset->path));
    $archive = new ZipArchive;
    $archive->open($temporaryFile);
    $archive->addFromString('xl/externalLinks/externalLink1.xml', '<externalLink/>');
    $archive->close();
    Storage::disk(FileAsset::PRIVATE_DISK)->put($sourceAsset->path, file_get_contents($temporaryFile));
    unlink($temporaryFile);

    $run = app(QueueTemplateExport::class)->handle(
        $this->organization,
        $this->owner,
        $timetableVersion,
        $templateVersion,
        ExportRunPurpose::Preview,
    );
    $job = new GenerateTemplateExport($this->organization->public_id, $run->public_id);

    expect(fn (): mixed => app(TenantContext::class)->run(
        $this->organization,
        fn (): mixed => $job->handle(app(RenderTemplateExport::class)),
    ))->toThrow(LogicException::class);

    expect($run->refresh()->error)->toContain('external links');
});

/**
 * @param  array{cell: string, key: string}  $placeholder
 * @return array{0: TimetableVersion, 1: ExcelTemplateVersion}
 */
function exportInputs(Organization $organization, array $placeholder = ['key' => 'organization_name', 'cell' => 'A1']): array
{
    $academicYear = AcademicYear::factory()->forOrganization($organization)->create();
    $academicPeriod = AcademicPeriod::factory()->forAcademicYear($academicYear)->create();
    $timetable = Timetable::factory()->create([
        'organization_id' => $organization->id,
        'academic_year_id' => $academicYear->id,
        'academic_period_id' => $academicPeriod->id,
    ]);
    $timetableVersion = TimetableVersion::factory()->create([
        'organization_id' => $organization->id,
        'timetable_id' => $timetable->id,
    ]);

    $spreadsheet = new Spreadsheet;
    $worksheet = $spreadsheet->getActiveSheet();
    $worksheet->setTitle('Schedule');
    $worksheet->mergeCells('A1:A2');
    $worksheet->setCellValue('F1', '=1+1');
    $temporaryFile = tempnam(sys_get_temp_dir(), 'talasched-template-test-');
    (new Xlsx($spreadsheet))->save($temporaryFile);
    $contents = file_get_contents($temporaryFile);
    unlink($temporaryFile);
    $spreadsheet->disconnectWorksheets();

    $path = 'organizations/'.$organization->public_id.'/workbooks/export-template.xlsx';
    Storage::disk(FileAsset::PRIVATE_DISK)->put($path, $contents);
    $asset = FileAsset::factory()->create([
        'organization_id' => $organization->id,
        'path' => $path,
        'size' => strlen($contents),
        'checksum' => hash('sha256', $contents),
        'scan_status' => FileAsset::ScanClean,
    ]);
    $template = ExcelTemplate::factory()->forOrganization($organization)->create();
    $templateVersion = ExcelTemplateVersion::factory()->forTemplate($template, $asset)->create([
        'mapping' => [
            'worksheet' => 'Schedule',
            'timetable_area' => ['start_cell' => 'B3', 'end_cell' => 'C4'],
            'day_columns' => [
                ['weekday' => 1, 'column' => 'B'],
                ['weekday' => 2, 'column' => 'C'],
            ],
            'time_rows' => [
                ['row' => 3, 'start_minute' => 480],
                ['row' => 4, 'start_minute' => 510],
            ],
            'schedule_cell' => ['format' => '{{subject_code}} {{group_name}}'],
            'placeholders' => [$placeholder],
            'signatory_fields' => [],
        ],
    ]);

    return [$timetableVersion, $templateVersion];
}
