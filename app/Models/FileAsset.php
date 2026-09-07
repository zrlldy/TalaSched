<?php

namespace App\Models;

use App\Concerns\HasImmutableOrganization;
use App\Concerns\HasPublicId;
use Database\Factories\FileAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['organization_id', 'disk', 'path', 'original_name', 'mime_type', 'size', 'checksum', 'scan_status'])]
#[Hidden(['disk', 'path', 'checksum'])]
class FileAsset extends Model
{
    /** @use HasFactory<FileAssetFactory> */
    use HasFactory, HasImmutableOrganization, HasPublicId;

    public const string PRIVATE_DISK = 'private';

    public const string ScanPending = 'pending';

    public const string ScanClean = 'clean';

    public const string ScanFailed = 'failed';

    public const string ScanQuarantined = 'quarantined';

    protected $attributes = [
        'scan_status' => self::ScanPending,
    ];

    protected static function booted(): void
    {
        static::saving(function (FileAsset $asset): void {
            $asset->assertPrivateAssetMetadata();
        });

        static::updating(function (FileAsset $asset): void {
            foreach (['public_id', 'disk', 'path', 'original_name', 'mime_type', 'size', 'checksum'] as $attribute) {
                if ($asset->isDirty($attribute)) {
                    throw new LogicException("File asset {$attribute} cannot be changed after creation.");
                }
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    private function assertPrivateAssetMetadata(): void
    {
        if ($this->disk !== self::PRIVATE_DISK) {
            throw new LogicException('File assets must use the private storage disk.');
        }

        if ($this->path === '') {
            throw new LogicException('File assets require an immutable storage path.');
        }

        if ($this->original_name === '') {
            throw new LogicException('File assets require an original filename.');
        }

        if ($this->mime_type === '') {
            throw new LogicException('File assets require a MIME type.');
        }

        if (strlen($this->checksum) !== 64
            || ! ctype_xdigit($this->checksum)) {
            throw new LogicException('File assets require a SHA-256 checksum.');
        }

        if (! in_array($this->scan_status, [self::ScanPending, self::ScanClean, self::ScanFailed, self::ScanQuarantined], true)) {
            throw new LogicException('File assets require a recognized scan status.');
        }
    }
}
