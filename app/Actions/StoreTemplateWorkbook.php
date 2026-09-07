<?php

namespace App\Actions;

use App\Audit\AuditLogger;
use App\Jobs\ScanFileAsset;
use App\Models\FileAsset;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Throwable;

class StoreTemplateWorkbook
{
    private const int MaximumWorkbookSizeKilobytes = 10_240;

    public function __construct(
        private TenantContext $tenantContext,
        private ValidateTemplateWorkbookArchive $archiveValidator,
        private AuditLogger $auditLogger,
    ) {}

    public function handle(Organization $organization, User $actor, UploadedFile $uploadedWorkbook): FileAsset
    {
        return $this->tenantContext->run($organization, function () use ($organization, $actor, $uploadedWorkbook): FileAsset {
            Gate::forUser($actor)->authorize('create', [FileAsset::class, $organization]);

            $metadata = $this->workbookMetadata($uploadedWorkbook);
            $storedPath = null;

            try {
                return DB::transaction(function () use ($organization, $actor, $uploadedWorkbook, $metadata, &$storedPath): FileAsset {
                    Organization::query()
                        ->whereKey($organization->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();

                    $existing = FileAsset::query()
                        ->where('organization_id', $organization->getKey())
                        ->where('disk', FileAsset::PRIVATE_DISK)
                        ->where('checksum', $metadata['checksum'])
                        ->first();

                    if ($existing !== null) {
                        if ($existing->scan_status === FileAsset::ScanPending) {
                            $this->queueScan($organization, $existing);
                        }

                        return $existing;
                    }

                    $storedPath = $this->storeWorkbook($organization, $uploadedWorkbook);
                    $asset = FileAsset::query()->create([
                        'organization_id' => $organization->getKey(),
                        'disk' => FileAsset::PRIVATE_DISK,
                        'path' => $storedPath,
                        'original_name' => $metadata['original_name'],
                        'mime_type' => $metadata['mime_type'],
                        'size' => $metadata['size'],
                        'checksum' => $metadata['checksum'],
                        'scan_status' => FileAsset::ScanPending,
                    ]);

                    $this->auditLogger->record(
                        action: 'template_workbook.uploaded',
                        organization: $organization,
                        actor: $actor,
                        subject: $asset,
                        after: [
                            'original_name' => $asset->original_name,
                            'mime_type' => $asset->mime_type,
                            'size' => $asset->size,
                            'scan_status' => $asset->scan_status,
                        ],
                    );

                    $this->queueScan($organization, $asset);

                    return $asset;
                });
            } catch (Throwable $exception) {
                if ($storedPath !== null) {
                    Storage::disk(FileAsset::PRIVATE_DISK)->delete($storedPath);
                }

                throw $exception;
            }
        });
    }

    /**
     * @return array{checksum: string, mime_type: string, original_name: string, size: int}
     */
    private function workbookMetadata(UploadedFile $uploadedWorkbook): array
    {
        Validator::make(
            ['workbook' => $uploadedWorkbook],
            [
                'workbook' => [
                    'required',
                    'file',
                    'mimes:xlsx',
                    'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip',
                    'max:'.self::MaximumWorkbookSizeKilobytes,
                ],
            ],
        )->validate();

        $realPath = $uploadedWorkbook->getRealPath();

        if (! is_string($realPath) || $realPath === '') {
            throw new RuntimeException('The workbook could not be opened for archive validation.');
        }

        try {
            $this->archiveValidator->validate($realPath);
        } catch (LogicException $exception) {
            throw ValidationException::withMessages([
                'workbook' => $exception->getMessage(),
            ]);
        }

        $checksum = hash_file('sha256', $realPath);

        if ($checksum === false) {
            throw new RuntimeException('The workbook could not be checksummed.');
        }

        $size = $uploadedWorkbook->getSize();

        if (! is_int($size)) {
            throw new RuntimeException('The workbook size could not be determined.');
        }

        $originalName = Str::squish(str_replace('\\', '/', $uploadedWorkbook->getClientOriginalName()));

        return [
            'checksum' => $checksum,
            'mime_type' => (string) $uploadedWorkbook->getMimeType(),
            'original_name' => Str::limit(basename($originalName), 255, ''),
            'size' => $size,
        ];
    }

    private function storeWorkbook(Organization $organization, UploadedFile $uploadedWorkbook): string
    {
        $path = Storage::disk(FileAsset::PRIVATE_DISK)->putFileAs(
            'organizations/'.Str::lower($organization->public_id).'/workbooks',
            $uploadedWorkbook,
            Str::uuid().'.xlsx',
        );

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('The workbook could not be stored.');
        }

        return $path;
    }

    private function queueScan(Organization $organization, FileAsset $asset): void
    {
        DB::afterCommit(function () use ($organization, $asset): void {
            ScanFileAsset::dispatch($organization->public_id, $asset->public_id)
                ->onQueue('security');
        });
    }
}
