<?php

use App\Actions\QueueTemplateExport;
use App\Actions\RenderTemplateExport;
use App\Approvals\SignatoryProfileService;
use App\Enums\ExportRunPurpose;
use App\Enums\ExportRunStatus;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\ExcelTemplate;
use App\Models\ExcelTemplateVersion;
use App\Models\FileAsset;
use App\Models\Organization;
use App\Models\SignatoryProfile;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function (): void {
    Storage::fake(FileAsset::PRIVATE_DISK);
    Storage::fake(SignatoryProfile::SIGNATURE_DISK);
    $this->owner = User::factory()->withOwnedOrganization()->create();
    $this->organization = $this->owner->currentOrganization;
    $this->organization->update(['timezone' => 'Asia/Manila']);
    grantApprovalWorkflowsEntitlement($this->organization);
    grantCustomExcelTemplatesEntitlement($this->organization);
    $approvalCapabilityId = (int) DB::table('capabilities')
        ->where('code', 'approval_workflows')
        ->value('id');

    app(TenantContext::class)->run($this->organization, function () use ($approvalCapabilityId): void {
        DB::table('organization_entitlement_overrides')->insert([
            'organization_id' => $this->organization->id,
            'capability_id' => $approvalCapabilityId,
            'boolean_value' => true,
            'reason' => 'Golden workbook requires its approved signatory fixture.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
});

test('preserves the golden workbook while rendering immutable approval signatories', function (): void {
    [$timetableVersion, $templateVersion] = goldenWorkbookInputs($this->organization);
    $profile = app(SignatoryProfileService::class)->create(
        $this->organization,
        $this->owner,
        $this->owner,
        'Former Registrar',
        'Registrar',
        signatureImage: UploadedFile::fake()->image('former-registrar.png', 80, 30),
    );
    $historicalSignature = [
        'checksum' => $profile->signature_checksum,
        'path' => $profile->signature_path,
    ];
    goldenApprovalSnapshot($this->organization, $timetableVersion, $this->owner, $profile);

    app(SignatoryProfileService::class)->update(
        $this->organization,
        $this->owner,
        $profile,
        'Current Registrar',
        'Chief Registrar',
        signatureImage: UploadedFile::fake()->image('current-registrar.png', 100, 40),
    );

    Storage::disk(SignatoryProfile::SIGNATURE_DISK)->assertExists($historicalSignature['path']);

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

    $temporaryFile = tempnam(sys_get_temp_dir(), 'talasched-golden-export-');
    file_put_contents($temporaryFile, Storage::disk(FileAsset::PRIVATE_DISK)->get($run->artifact->path));
    $spreadsheet = IOFactory::load($temporaryFile);
    $worksheet = $spreadsheet->getSheetByName('Schedule');
    $drawings = [];

    foreach ($worksheet->getDrawingCollection() as $drawing) {
        $drawings[$drawing->getName()] = $drawing;
    }

    expect($worksheet->getCell('A1')->getValue())->toBe($this->organization->name)
        ->and($worksheet->getCell('F10')->getValue())->toBe('Former Registrar')
        ->and($worksheet->getCell('F11')->getValue())->toBe('Registrar')
        ->and($worksheet->getStyle('A1')->getFont()->getBold())->toBeTrue()
        ->and($worksheet->getStyle('A1')->getFill()->getStartColor()->getARGB())->toBe('FF0F766E')
        ->and($worksheet->getMergeCells())->toHaveKey('A1:B1')
        ->and($worksheet->getColumnDimension('B')->getWidth())->toEqual(28.0)
        ->and($worksheet->getRowDimension(3)->getRowHeight())->toEqual(36.0)
        ->and($worksheet->getPageSetup()->getOrientation())->toBe(PageSetup::ORIENTATION_LANDSCAPE)
        ->and($worksheet->getPageSetup()->getFitToWidth())->toBe(1)
        ->and(str_replace('$', '', $worksheet->getPageSetup()->getPrintArea()))->toBe('A1:H20')
        ->and($drawings)->toHaveKeys(['Institution logo', 'Approved signatory'])
        ->and($drawings['Institution logo']->getCoordinates())->toBe('G1')
        ->and($drawings['Approved signatory']->getCoordinates())->toBe('F12');

    $spreadsheet->disconnectWorksheets();
    unlink($temporaryFile);
});

/**
 * @return array{0: TimetableVersion, 1: ExcelTemplateVersion}
 */
function goldenWorkbookInputs(Organization $organization): array
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
    $worksheet->mergeCells('A1:B1');
    $worksheet->setCellValue('A1', 'Organization placeholder');
    $worksheet->getStyle('A1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $worksheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0F766E');
    $worksheet->getColumnDimension('B')->setWidth(28);
    $worksheet->getRowDimension(3)->setRowHeight(36);
    $worksheet->getPageSetup()
        ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
        ->setFitToWidth(1)
        ->setFitToHeight(0)
        ->setPrintArea('A1:H20');
    $worksheet->setCellValue('H20', '=1+1');

    $logoFile = tempnam(sys_get_temp_dir(), 'talasched-golden-logo-');
    file_put_contents($logoFile, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL0EQAAAABJRU5ErkJggg==', true));
    (new Drawing)
        ->setName('Institution logo')
        ->setDescription('Institution logo')
        ->setPath($logoFile)
        ->setHeight(28)
        ->setCoordinates('G1')
        ->setWorksheet($worksheet);

    $temporaryFile = tempnam(sys_get_temp_dir(), 'talasched-golden-template-');
    (new Xlsx($spreadsheet))->save($temporaryFile);
    $contents = file_get_contents($temporaryFile);
    unlink($temporaryFile);
    unlink($logoFile);
    $spreadsheet->disconnectWorksheets();

    $path = 'organizations/'.$organization->public_id.'/workbooks/golden-template.xlsx';
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
            'placeholders' => [['key' => 'organization_name', 'cell' => 'A1']],
            'signatory_fields' => [[
                'role' => 'registrar',
                'name_cell' => 'F10',
                'position_cell' => 'F11',
                'signature_cell' => 'F12',
            ]],
        ],
    ]);

    return [$timetableVersion, $templateVersion];
}

function goldenApprovalSnapshot(
    Organization $organization,
    TimetableVersion $timetableVersion,
    User $approver,
    SignatoryProfile $profile,
): void {
    $timestamp = now();
    $workflowId = DB::table('approval_workflows')->insertGetId([
        'organization_id' => $organization->id,
        'public_id' => (string) Str::uuid(),
        'name' => 'Golden approval '.Str::uuid(),
        'is_active' => true,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    $workflowVersionId = DB::table('approval_workflow_versions')->insertGetId([
        'organization_id' => $organization->id,
        'approval_workflow_id' => $workflowId,
        'version_number' => 1,
        'activated_at' => $timestamp,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    $instanceId = DB::table('approval_instances')->insertGetId([
        'organization_id' => $organization->id,
        'timetable_version_id' => $timetableVersion->id,
        'approval_workflow_version_id' => $workflowVersionId,
        'status' => 'approved',
        'submitted_by' => $approver->id,
        'completed_at' => $timestamp,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    $stepId = DB::table('approval_instance_steps')->insertGetId([
        'organization_id' => $organization->id,
        'approval_instance_id' => $instanceId,
        'sequence' => 1,
        'label' => 'Registrar approval',
        'minimum_approvals' => 1,
        'allow_self_approval' => false,
        'status' => 'approved',
        'signatory_slot' => 'registrar',
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);

    DB::table('approval_actions')->insert([
        'organization_id' => $organization->id,
        'approval_instance_step_id' => $stepId,
        'actor_user_id' => $approver->id,
        'decision' => 'approve',
        'signatory_name' => $profile->name,
        'signatory_position' => $profile->position,
        'signatory_academic_unit' => $profile->academic_unit_name,
        'signature_disk' => $profile->signature_disk,
        'signature_path' => $profile->signature_path,
        'signature_checksum' => $profile->signature_checksum,
        'acted_at' => $timestamp,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
}
