<?php

namespace App\Actions;

use App\Audit\AuditLogger;
use App\Enums\ExcelTemplateStatus;
use App\Models\ExcelTemplate;
use App\Models\ExcelTemplateVersion;
use App\Models\FileAsset;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateExcelTemplateVersion
{
    public function __construct(
        private TenantContext $tenantContext,
        private InspectTemplateWorkbook $inspectWorkbook,
        private ValidateTemplateWorkbookMapping $validateMapping,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $mapping
     */
    public function handle(
        Organization $organization,
        User $actor,
        FileAsset $sourceAsset,
        string $name,
        array $mapping,
        ?ExcelTemplate $template = null,
    ): ExcelTemplateVersion {
        return $this->tenantContext->run($organization, function () use ($organization, $actor, $sourceAsset, $name, $mapping, $template): ExcelTemplateVersion {
            Gate::forUser($actor)->authorize('create', [ExcelTemplateVersion::class, $organization]);

            $asset = FileAsset::query()
                ->whereKey($sourceAsset->getKey())
                ->where('organization_id', $organization->getKey())
                ->firstOrFail();
            $inspection = $this->inspectWorkbook->handle($organization, $actor, $asset);
            $validatedMapping = $this->validateMapping->handle($organization, $inspection, $mapping);
            $normalizedName = Str::squish($name);

            if ($template === null && $normalizedName === '') {
                throw new InvalidArgumentException('A template name is required for a new template.');
            }

            return DB::transaction(function () use ($organization, $actor, $asset, $validatedMapping, $normalizedName, $template): ExcelTemplateVersion {
                Organization::query()
                    ->whereKey($organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $persistedTemplate = $template === null
                    ? $this->templateForName($organization, $normalizedName)
                    : ExcelTemplate::query()
                        ->whereKey($template->getKey())
                        ->where('organization_id', $organization->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();

                Gate::forUser($actor)->authorize('update', $persistedTemplate);

                $versionNumber = (int) ExcelTemplateVersion::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('excel_template_id', $persistedTemplate->getKey())
                    ->max('version_number') + 1;
                $version = ExcelTemplateVersion::query()->create([
                    'organization_id' => $organization->getKey(),
                    'excel_template_id' => $persistedTemplate->getKey(),
                    'file_asset_id' => $asset->getKey(),
                    'version_number' => $versionNumber,
                    'mapping_schema_version' => 1,
                    'mapping' => $validatedMapping->toArray(),
                    'activated_at' => null,
                ]);

                $this->auditLogger->record(
                    action: 'excel_template.version_created',
                    organization: $organization,
                    actor: $actor,
                    subject: $version,
                    after: [
                        'source_asset_id' => $asset->public_id,
                        'template_id' => $persistedTemplate->public_id,
                        'version_number' => $versionNumber,
                        'worksheet' => $validatedMapping->worksheet,
                    ],
                );

                return $version;
            }, attempts: 3);
        });
    }

    private function templateForName(Organization $organization, string $name): ExcelTemplate
    {
        $template = ExcelTemplate::query()
            ->where('organization_id', $organization->getKey())
            ->where('name', $name)
            ->lockForUpdate()
            ->first();

        if ($template instanceof ExcelTemplate) {
            return $template;
        }

        return ExcelTemplate::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => $name,
            'status' => ExcelTemplateStatus::Draft,
        ]);
    }
}
