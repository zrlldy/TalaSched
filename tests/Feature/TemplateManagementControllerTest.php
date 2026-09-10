<?php

use App\Enums\ExportRunPurpose;
use App\Jobs\GenerateTemplateExport;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\ExcelTemplate;
use App\Models\ExcelTemplateVersion;
use App\Models\FileAsset;
use App\Models\Organization;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

beforeEach(function (): void {
    Storage::fake(FileAsset::PRIVATE_DISK);
    $this->owner = User::factory()->withOwnedOrganization()->create();
    $this->organization = $this->owner->currentOrganization;
    grantCustomExcelTemplatesEntitlement($this->organization);
});

test('template management exposes a clean workbook mapper and saves immutable draft versions', function (): void {
    $asset = templateManagementWorkbook($this->organization);

    $this->actingAs($this->owner)
        ->get(route('templates.index', [
            'current_organization' => $this->organization->slug,
            'workbook' => $asset->public_id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('templates/Index')
            ->where('uploadedWorkbook.id', $asset->public_id)
            ->where('uploadedWorkbook.scan_status', FileAsset::ScanClean)
            ->where('uploadedWorkbook.inspection.0.name', 'Schedule')
            ->where('canManageTemplates', true)
            ->where('canCreateTemplateVersions', true)
            ->has('placeholderCatalog', 9));

    $this->actingAs($this->owner)
        ->post(route('templates.versions.store', [
            'current_organization' => $this->organization->slug,
        ]), [
            'name' => 'Registrar weekly layout',
            'file_asset_id' => $asset->public_id,
            'mapping' => templateManagementMapping(),
        ])
        ->assertRedirect(route('templates.index', [
            'current_organization' => $this->organization->slug,
        ]));

    $template = ExcelTemplate::query()
        ->where('organization_id', $this->organization->getKey())
        ->where('name', 'Registrar weekly layout')
        ->firstOrFail();
    $version = $template->versions()->firstOrFail();

    expect($template->status->value)->toBe('draft')
        ->and($version->public_id)->not->toBe((string) $version->getKey())
        ->and($version->version_number)->toBe(1)
        ->and($version->mapping['day_columns'])->toBe([
            ['column' => 'B', 'weekday' => 1],
            ['column' => 'C', 'weekday' => 2],
        ])
        ->and($version->mapping['schedule_cell'])->toBe([
            'format' => '{{subject_code}} {{group_name}}',
        ])
        ->and($version->activated_at)->toBeNull();

    $this->actingAs($this->owner)
        ->post(route('templates.versions.activate', [
            'current_organization' => $this->organization->slug,
            'excel_template_version' => $version->public_id,
        ]))
        ->assertRedirect(route('templates.index', [
            'current_organization' => $this->organization->slug,
        ]));

    expect($template->fresh()->status->value)->toBe('active')
        ->and($version->fresh()->activated_at)->not->toBeNull();
});

test('template management stores workbook uploads privately before security scanning', function (): void {
    $this->actingAs($this->owner)
        ->post(route('templates.workbooks.store', [
            'current_organization' => $this->organization->slug,
        ]), [
            'workbook' => UploadedFile::fake()->createWithContent(
                'registrar-layout.xlsx',
                templateManagementWorkbookContents(),
            ),
        ])
        ->assertRedirectContains(route('templates.index', [
            'current_organization' => $this->organization->slug,
        ]));

    $asset = FileAsset::query()
        ->where('organization_id', $this->organization->getKey())
        ->firstOrFail();

    expect($asset->scan_status)->toBe(FileAsset::ScanPending);
    Storage::disk(FileAsset::PRIVATE_DISK)->assertExists($asset->path);
});

test('template management queues a preview through public identifiers', function (): void {
    $asset = templateManagementWorkbook($this->organization);
    $template = ExcelTemplate::factory()->forOrganization($this->organization)->create();
    $templateVersion = ExcelTemplateVersion::factory()->forTemplate($template, $asset)->create([
        'mapping' => templateManagementMapping(),
    ]);
    $timetableVersion = templateManagementTimetableVersion($this->organization);
    Queue::fake();

    $this->actingAs($this->owner)
        ->post(route('templates.exports.store', [
            'current_organization' => $this->organization->slug,
        ]), [
            'template_version_id' => $templateVersion->public_id,
            'timetable_version_id' => $timetableVersion->public_id,
            'purpose' => ExportRunPurpose::Preview->value,
        ])
        ->assertRedirect(route('templates.index', [
            'current_organization' => $this->organization->slug,
        ]));

    Queue::assertPushedOn('workbooks', GenerateTemplateExport::class);

    $this->actingAs($this->owner)
        ->get(route('templates.index', ['current_organization' => $this->organization->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('runs.0.purpose', ExportRunPurpose::Preview->value)
            ->where('runs.0.template_version_number', 1)
            ->where('runs.0.timetable_version_number', $timetableVersion->version_number));
});

test('template commands stay unavailable to members without scheduling permission', function (): void {
    $member = User::factory()->create();
    $this->organization->members()->attach($member, ['role' => 'member']);
    $asset = templateManagementWorkbook($this->organization);

    $this->actingAs($member)
        ->get(route('templates.index', ['current_organization' => $this->organization->slug]))
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('templates.versions.store', [
            'current_organization' => $this->organization->slug,
        ]), [
            'name' => 'Unauthorized template',
            'file_asset_id' => $asset->public_id,
            'mapping' => templateManagementMapping(),
        ])
        ->assertForbidden();
});

test('template navigation remains available when the organization cannot create template versions', function (): void {
    $owner = User::factory()->withOwnedOrganization()->create();

    $this->actingAs($owner)
        ->get(route('templates.index', $owner->currentOrganization))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('templates/Index')
            ->where('canManageTemplates', true)
            ->where('canCreateTemplateVersions', false));
});

function templateManagementWorkbook(Organization $organization): FileAsset
{
    $contents = templateManagementWorkbookContents();
    $path = 'organizations/'.$organization->public_id.'/workbooks/management-template.xlsx';
    Storage::disk(FileAsset::PRIVATE_DISK)->put($path, $contents);

    return FileAsset::factory()->create([
        'organization_id' => $organization->getKey(),
        'path' => $path,
        'size' => strlen($contents),
        'checksum' => hash('sha256', $contents),
        'scan_status' => FileAsset::ScanClean,
    ]);
}

function templateManagementWorkbookContents(): string
{
    $spreadsheet = new Spreadsheet;
    $worksheet = $spreadsheet->getActiveSheet();
    $worksheet->setTitle('Schedule');
    $worksheet->setCellValue('A1', 'Organization');
    $worksheet->setCellValue('B2', 'Monday');
    $worksheet->setCellValue('C2', 'Tuesday');
    $worksheet->setCellValue('B3', '08:00');
    $worksheet->setCellValue('C4', '09:00');
    $temporaryPath = tempnam(sys_get_temp_dir(), 'talasched-template-management-');

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
        throw new RuntimeException('The template workbook could not be read.');
    }

    return $contents;
}

/** @return array<string, mixed> */
function templateManagementMapping(): array
{
    return [
        'worksheet' => 'Schedule',
        'timetable_area' => ['start_cell' => 'B2', 'end_cell' => 'C4'],
        'day_columns' => [
            ['weekday' => 1, 'column' => 'B'],
            ['weekday' => 2, 'column' => 'C'],
        ],
        'time_rows' => [
            ['row' => 3, 'start_minute' => 480],
            ['row' => 4, 'start_minute' => 510],
        ],
        'schedule_cell' => ['format' => '{{subject_code}} {{group_name}}'],
        'placeholders' => [['key' => 'organization_name', 'cell' => 'A1']],
        'signatory_fields' => [],
    ];
}

function templateManagementTimetableVersion(Organization $organization): TimetableVersion
{
    $year = AcademicYear::factory()->forOrganization($organization)->create();
    $period = AcademicPeriod::factory()->forAcademicYear($year)->create();
    $timetable = Timetable::factory()->create([
        'organization_id' => $organization->getKey(),
        'academic_year_id' => $year->getKey(),
        'academic_period_id' => $period->getKey(),
    ]);

    return TimetableVersion::factory()->create([
        'organization_id' => $organization->getKey(),
        'timetable_id' => $timetable->getKey(),
    ]);
}
