<?php

namespace App\Actions;

use App\Audit\AuditLogger;
use App\Contracts\MalwareScanner;
use App\Enums\MalwareScanResult;
use App\Models\FileAsset;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ScanFileAsset
{
    public function __construct(
        private TenantContext $tenantContext,
        private MalwareScanner $malwareScanner,
        private AuditLogger $auditLogger,
    ) {}

    public function handle(Organization $organization, string $fileAssetPublicId): void
    {
        $this->tenantContext->run($organization, function () use ($organization, $fileAssetPublicId): void {
            $asset = $this->pendingAsset($organization, $fileAssetPublicId);

            if ($asset === null) {
                return;
            }

            $result = $this->scan($asset);

            DB::transaction(function () use ($organization, $fileAssetPublicId, $result): void {
                $lockedAsset = FileAsset::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('public_id', $fileAssetPublicId)
                    ->lockForUpdate()
                    ->first();

                if ($lockedAsset === null || $lockedAsset->scan_status !== FileAsset::ScanPending) {
                    return;
                }

                $before = ['scan_status' => $lockedAsset->scan_status];
                $lockedAsset->scan_status = $result === MalwareScanResult::Clean
                    ? FileAsset::ScanClean
                    : FileAsset::ScanQuarantined;
                $lockedAsset->save();

                $this->auditLogger->record(
                    action: $result === MalwareScanResult::Clean ? 'file_asset.scanned' : 'file_asset.quarantined',
                    organization: $organization,
                    subject: $lockedAsset,
                    before: $before,
                    after: ['scan_status' => $lockedAsset->scan_status],
                );
            });
        });
    }

    public function fail(Organization $organization, string $fileAssetPublicId, ?Throwable $exception): void
    {
        $this->tenantContext->run($organization, function () use ($organization, $fileAssetPublicId, $exception): void {
            DB::transaction(function () use ($organization, $fileAssetPublicId, $exception): void {
                $asset = FileAsset::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('public_id', $fileAssetPublicId)
                    ->lockForUpdate()
                    ->first();

                if ($asset === null || $asset->scan_status !== FileAsset::ScanPending) {
                    return;
                }

                $before = ['scan_status' => $asset->scan_status];
                $asset->scan_status = FileAsset::ScanFailed;
                $asset->save();

                $this->auditLogger->record(
                    action: 'file_asset.scan_failed',
                    organization: $organization,
                    subject: $asset,
                    before: $before,
                    after: [
                        'failure_type' => $exception === null ? null : $exception::class,
                        'scan_status' => $asset->scan_status,
                    ],
                );
            });
        });
    }

    private function pendingAsset(Organization $organization, string $fileAssetPublicId): ?FileAsset
    {
        return FileAsset::query()
            ->where('organization_id', $organization->getKey())
            ->where('public_id', $fileAssetPublicId)
            ->where('scan_status', FileAsset::ScanPending)
            ->first();
    }

    private function scan(FileAsset $asset): MalwareScanResult
    {
        $stream = Storage::disk($asset->disk)->readStream($asset->path);

        if (! is_resource($stream)) {
            throw new RuntimeException('The uploaded file could not be opened for malware scanning.');
        }

        try {
            return $this->malwareScanner->scan($stream);
        } finally {
            fclose($stream);
        }
    }
}
