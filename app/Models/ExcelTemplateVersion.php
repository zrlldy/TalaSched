<?php

namespace App\Models;

use App\Concerns\HasImmutableOrganization;
use App\Concerns\HasPublicId;
use Database\Factories\ExcelTemplateVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $public_id
 * @property int $organization_id
 * @property int $excel_template_id
 * @property int $file_asset_id
 * @property int $version_number
 * @property int $mapping_schema_version
 * @property array<string, mixed> $mapping
 * @property Carbon|null $activated_at
 * @property-read Organization $organization
 * @property-read ExcelTemplate $template
 * @property-read FileAsset $sourceAsset
 * @property-read Collection<int, ExportRun> $exportRuns
 */
#[Fillable(['organization_id', 'excel_template_id', 'file_asset_id', 'version_number', 'mapping_schema_version', 'mapping', 'activated_at'])]
class ExcelTemplateVersion extends Model
{
    /** @use HasFactory<ExcelTemplateVersionFactory> */
    use HasFactory, HasImmutableOrganization, HasPublicId;

    protected static function booted(): void
    {
        static::saving(function (ExcelTemplateVersion $version): void {
            $templateExists = ExcelTemplate::query()
                ->whereKey($version->excel_template_id)
                ->where('organization_id', $version->organization_id)
                ->exists();
            $sourceAssetIsClean = FileAsset::query()
                ->whereKey($version->file_asset_id)
                ->where('organization_id', $version->organization_id)
                ->where('scan_status', FileAsset::ScanClean)
                ->exists();

            if (! $templateExists || ! $sourceAssetIsClean) {
                throw new LogicException('A template version must reference a clean source asset and template from its organization.');
            }

            if ($version->version_number < 1 || $version->mapping_schema_version < 1) {
                throw new LogicException('A template version requires a positive version and supported mapping schema.');
            }
        });

        static::updating(function (ExcelTemplateVersion $version): void {
            foreach (['public_id', 'excel_template_id', 'file_asset_id', 'version_number', 'mapping_schema_version', 'mapping'] as $attribute) {
                if ($version->isDirty($attribute)) {
                    throw new LogicException("Template version {$attribute} cannot be changed after creation.");
                }
            }

            if ($version->isDirty('activated_at') && $version->getRawOriginal('activated_at') !== null) {
                throw new LogicException('A template version activation timestamp cannot be changed after activation.');
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<ExcelTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ExcelTemplate::class, 'excel_template_id');
    }

    /** @return BelongsTo<FileAsset, $this> */
    public function sourceAsset(): BelongsTo
    {
        return $this->belongsTo(FileAsset::class, 'file_asset_id');
    }

    /** @return HasMany<ExportRun, $this> */
    public function exportRuns(): HasMany
    {
        return $this->hasMany(ExportRun::class);
    }

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'mapping_schema_version' => 'integer',
            'version_number' => 'integer',
            'activated_at' => 'datetime',
        ];
    }
}
