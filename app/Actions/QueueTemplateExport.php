<?php

namespace App\Actions;

use App\Audit\AuditLogger;
use App\Enums\ExportRunPurpose;
use App\Enums\ExportRunStatus;
use App\Enums\TimetableVersionStatus;
use App\Jobs\GenerateTemplateExport;
use App\Models\ExcelTemplateVersion;
use App\Models\ExportRun;
use App\Models\FileAsset;
use App\Models\Organization;
use App\Models\TimetableVersion;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class QueueTemplateExport
{
    public function __construct(
        private TenantContext $tenantContext,
        private AuditLogger $audit,
    ) {}

    public function handle(
        Organization $organization,
        User $actor,
        TimetableVersion $timetableVersion,
        ExcelTemplateVersion $templateVersion,
        ExportRunPurpose $purpose = ExportRunPurpose::Export,
    ): ExportRun {
        return $this->tenantContext->run($organization, function () use ($organization, $actor, $timetableVersion, $templateVersion, $purpose): ExportRun {
            Gate::forUser($actor)->authorize('create', [ExportRun::class, $organization]);

            $sourceVersion = TimetableVersion::query()
                ->whereKey($timetableVersion->getKey())
                ->where('organization_id', $organization->getKey())
                ->firstOrFail();
            $sourceTemplateVersion = ExcelTemplateVersion::query()
                ->with('sourceAsset')
                ->whereKey($templateVersion->getKey())
                ->where('organization_id', $organization->getKey())
                ->firstOrFail();

            if ($purpose === ExportRunPurpose::Export && $sourceVersion->status !== TimetableVersionStatus::Published) {
                throw ValidationException::withMessages([
                    'timetable_version' => 'Only published timetable versions can be exported. Use a preview run for a draft.',
                ]);
            }

            if ($sourceTemplateVersion->sourceAsset->scan_status !== FileAsset::ScanClean) {
                throw ValidationException::withMessages([
                    'template_version' => 'The source workbook must complete security scanning before it can be rendered.',
                ]);
            }

            $inputHash = hash('sha256', json_encode([
                'mapping' => $sourceTemplateVersion->mapping,
                'purpose' => $purpose->value,
                'source_asset_checksum' => $sourceTemplateVersion->sourceAsset->checksum,
                'template_version' => $sourceTemplateVersion->public_id,
                'timetable_version' => $sourceVersion->public_id,
            ], JSON_THROW_ON_ERROR));

            return DB::transaction(function () use ($organization, $actor, $sourceVersion, $sourceTemplateVersion, $purpose, $inputHash): ExportRun {
                $existing = ExportRun::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('input_hash', $inputHash)
                    ->where('status', ExportRunStatus::Completed)
                    ->whereNotNull('artifact_file_asset_id')
                    ->latest('id')
                    ->first();

                if ($existing instanceof ExportRun) {
                    return $existing;
                }

                $run = ExportRun::query()->create([
                    'organization_id' => $organization->getKey(),
                    'timetable_version_id' => $sourceVersion->getKey(),
                    'excel_template_version_id' => $sourceTemplateVersion->getKey(),
                    'status' => ExportRunStatus::Pending,
                    'purpose' => $purpose,
                    'input_hash' => $inputHash,
                    'requested_by' => $actor->getKey(),
                ]);

                $this->audit->record('template_export.queued', $organization, $actor, $run, after: [
                    'purpose' => $purpose->value,
                    'timetable_version' => $sourceVersion->public_id,
                    'template_version' => $sourceTemplateVersion->public_id,
                ]);

                DB::afterCommit(function () use ($organization, $run): void {
                    GenerateTemplateExport::dispatch($organization->public_id, $run->public_id)
                        ->onQueue('workbooks');
                });

                return $run;
            });
        });
    }
}
