<?php

namespace App\Models;

use App\Concerns\HasImmutableOrganization;
use App\Concerns\HasPublicId;
use App\Enums\ExportRunPurpose;
use App\Enums\ExportRunStatus;
use Database\Factories\ExportRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $public_id
 * @property int $organization_id
 * @property int $timetable_version_id
 * @property int $excel_template_version_id
 * @property int|null $artifact_file_asset_id
 * @property ExportRunStatus $status
 * @property ExportRunPurpose $purpose
 * @property string $input_hash
 * @property string|null $error
 * @property int|null $requested_by
 * @property Carbon|null $completed_at
 * @property-read Organization $organization
 * @property-read TimetableVersion $timetableVersion
 * @property-read ExcelTemplateVersion $templateVersion
 * @property-read FileAsset|null $artifact
 * @property-read User|null $requestedBy
 */
#[Fillable(['organization_id', 'timetable_version_id', 'excel_template_version_id', 'artifact_file_asset_id', 'status', 'purpose', 'input_hash', 'error', 'requested_by', 'completed_at'])]
class ExportRun extends Model
{
    /** @use HasFactory<ExportRunFactory> */
    use HasFactory, HasImmutableOrganization, HasPublicId;

    protected static function booted(): void
    {
        static::saving(function (ExportRun $run): void {
            $hasTenantBoundInputs = TimetableVersion::query()
                ->whereKey($run->timetable_version_id)
                ->where('organization_id', $run->organization_id)
                ->exists()
                && ExcelTemplateVersion::query()
                    ->whereKey($run->excel_template_version_id)
                    ->where('organization_id', $run->organization_id)
                    ->exists();
            $artifactBelongsToOrganization = $run->artifact_file_asset_id === null
                || FileAsset::query()
                    ->whereKey($run->artifact_file_asset_id)
                    ->where('organization_id', $run->organization_id)
                    ->exists();

            if (! $hasTenantBoundInputs || ! $artifactBelongsToOrganization) {
                throw new LogicException('An export run and all of its referenced records must belong to one organization.');
            }

            if (strlen($run->input_hash) !== 64 || ! ctype_xdigit($run->input_hash)) {
                throw new LogicException('An export run requires a SHA-256 input hash.');
            }

            $run->assertLifecycleIntegrity();
        });

        static::creating(function (ExportRun $run): void {
            if ($run->status !== ExportRunStatus::Pending) {
                throw new LogicException('An export run must start pending.');
            }
        });

        static::updating(function (ExportRun $run): void {
            foreach (['public_id', 'timetable_version_id', 'excel_template_version_id', 'purpose', 'input_hash', 'requested_by'] as $attribute) {
                if ($run->isDirty($attribute)) {
                    throw new LogicException("Export run {$attribute} cannot be changed after creation.");
                }
            }

            $previous = ExportRunStatus::from((string) $run->getRawOriginal('status'));

            if ($previous !== $run->status && ! $run->allowsTransitionFrom($previous)) {
                throw new LogicException("Export run status cannot transition from {$previous->value} to {$run->status->value}.");
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<TimetableVersion, $this> */
    public function timetableVersion(): BelongsTo
    {
        return $this->belongsTo(TimetableVersion::class);
    }

    /** @return BelongsTo<ExcelTemplateVersion, $this> */
    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(ExcelTemplateVersion::class, 'excel_template_version_id');
    }

    /** @return BelongsTo<FileAsset, $this> */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(FileAsset::class, 'artifact_file_asset_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    private function allowsTransitionFrom(ExportRunStatus $previous): bool
    {
        return match ($previous) {
            ExportRunStatus::Pending => in_array($this->status, [ExportRunStatus::Running, ExportRunStatus::Failed], true),
            ExportRunStatus::Running => in_array($this->status, [ExportRunStatus::Completed, ExportRunStatus::Failed], true),
            ExportRunStatus::Completed, ExportRunStatus::Failed => false,
        };
    }

    private function assertLifecycleIntegrity(): void
    {
        if ($this->status === ExportRunStatus::Completed
            && ($this->artifact_file_asset_id === null || $this->completed_at === null || $this->error !== null)) {
            throw new LogicException('A completed export run requires an artifact and completion time without an error.');
        }

        if ($this->status === ExportRunStatus::Failed
            && ($this->completed_at === null || $this->error === null || $this->error === '')) {
            throw new LogicException('A failed export run requires an error and completion time.');
        }

        if (! $this->status->isTerminal()
            && ($this->artifact_file_asset_id !== null || $this->completed_at !== null || $this->error !== null)) {
            throw new LogicException('An incomplete export run cannot have an artifact, completion time, or error.');
        }
    }

    protected function casts(): array
    {
        return [
            'status' => ExportRunStatus::class,
            'purpose' => ExportRunPurpose::class,
            'completed_at' => 'datetime',
        ];
    }
}
