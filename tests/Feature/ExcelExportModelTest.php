<?php

use App\Enums\ExportRunStatus;
use App\Models\ExcelTemplate;
use App\Models\ExcelTemplateVersion;
use App\Models\ExportRun;
use App\Models\FileAsset;
use App\Models\Organization;
use App\Models\TimetableVersion;
use Illuminate\Support\Str;
use LogicException;

test('template versions retain clean tenant-owned source assets and immutable mappings', function (): void {
    $organization = Organization::factory()->create();
    $sourceAsset = FileAsset::factory()->create([
        'organization_id' => $organization->id,
        'scan_status' => FileAsset::ScanClean,
    ]);
    $template = ExcelTemplate::factory()->forOrganization($organization)->create();

    $version = ExcelTemplateVersion::factory()->forTemplate($template, $sourceAsset)->create();

    expect($version->template->is($template))->toBeTrue()
        ->and($version->sourceAsset->is($sourceAsset))->toBeTrue()
        ->and($version->mapping_schema_version)->toBe(1);

    $version->mapping = ['worksheet' => 'Replacement'];

    expect(fn (): bool => $version->save())->toThrow(LogicException::class);
});

test('export runs enforce tenant-bound immutable inputs and a terminal artifact lifecycle', function (): void {
    $timetableVersion = TimetableVersion::factory()->create();
    $organization = Organization::query()->findOrFail($timetableVersion->organization_id);
    $sourceAsset = FileAsset::factory()->create([
        'organization_id' => $organization->id,
        'scan_status' => FileAsset::ScanClean,
    ]);
    $template = ExcelTemplate::factory()->forOrganization($organization)->create();
    $templateVersion = ExcelTemplateVersion::factory()->forTemplate($template, $sourceAsset)->create();
    $run = ExportRun::factory()->forInputs($timetableVersion, $templateVersion)->create();

    $run->update(['status' => ExportRunStatus::Running]);

    $artifact = FileAsset::factory()->create([
        'organization_id' => $organization->id,
        'path' => 'organizations/'.$organization->public_id.'/exports/'.Str::uuid().'.xlsx',
        'scan_status' => FileAsset::ScanClean,
    ]);
    $run->update([
        'artifact_file_asset_id' => $artifact->id,
        'status' => ExportRunStatus::Completed,
        'completed_at' => now(),
    ]);

    expect($run->refresh()->status)->toBe(ExportRunStatus::Completed)
        ->and($run->artifact?->is($artifact))->toBeTrue()
        ->and($run->completed_at)->not->toBeNull();

    $run->input_hash = hash('sha256', 'different-input');

    expect(fn (): bool => $run->save())->toThrow(LogicException::class);
});

test('template versions cannot cross organization boundaries', function (): void {
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $sourceAsset = FileAsset::factory()->create([
        'organization_id' => $organization->id,
        'scan_status' => FileAsset::ScanClean,
    ]);
    $foreignTemplate = ExcelTemplate::factory()->forOrganization($foreignOrganization)->create();

    expect(fn (): ExcelTemplateVersion => ExcelTemplateVersion::query()->create([
        'organization_id' => $organization->id,
        'excel_template_id' => $foreignTemplate->id,
        'file_asset_id' => $sourceAsset->id,
        'version_number' => 1,
        'mapping_schema_version' => 1,
        'mapping' => [],
    ]))->toThrow(LogicException::class);
});
