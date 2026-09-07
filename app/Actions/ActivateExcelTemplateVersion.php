<?php

namespace App\Actions;

use App\Audit\AuditLogger;
use App\Enums\ExcelTemplateStatus;
use App\Models\ExcelTemplate;
use App\Models\ExcelTemplateVersion;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ActivateExcelTemplateVersion
{
    public function __construct(
        private TenantContext $tenantContext,
        private AuditLogger $auditLogger,
    ) {}

    public function handle(
        Organization $organization,
        User $actor,
        ExcelTemplateVersion $templateVersion,
    ): ExcelTemplateVersion {
        return $this->tenantContext->run($organization, function () use ($organization, $actor, $templateVersion): ExcelTemplateVersion {
            return DB::transaction(function () use ($organization, $actor, $templateVersion): ExcelTemplateVersion {
                $version = ExcelTemplateVersion::query()
                    ->whereKey($templateVersion->getKey())
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $template = ExcelTemplate::query()
                    ->whereKey($version->excel_template_id)
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                Gate::forUser($actor)->authorize('update', $template);

                if ($version->activated_at !== null) {
                    return $version;
                }

                $version->activated_at = Carbon::now();
                $version->save();
                $template->status = ExcelTemplateStatus::Active;
                $template->save();

                $this->auditLogger->record(
                    action: 'excel_template.version_activated',
                    organization: $organization,
                    actor: $actor,
                    subject: $version,
                    after: [
                        'template_id' => $template->public_id,
                        'version_number' => $version->version_number,
                    ],
                );

                return $version;
            }, attempts: 3);
        });
    }
}
