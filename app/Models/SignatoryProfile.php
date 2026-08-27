<?php

namespace App\Models;

use App\Concerns\HasImmutableOrganization;
use App\Concerns\HasPublicId;
use Carbon\CarbonInterface;
use Database\Factories\SignatoryProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * @property CarbonInterface|null $valid_from
 * @property CarbonInterface|null $valid_until
 */
#[Fillable(['organization_id', 'user_id', 'academic_unit_id', 'name', 'position', 'academic_unit_name', 'valid_from', 'valid_until'])]
#[Hidden(['signature_disk', 'signature_path', 'signature_checksum'])]
class SignatoryProfile extends Model
{
    /** @use HasFactory<SignatoryProfileFactory> */
    use HasFactory, HasImmutableOrganization, HasPublicId, SoftDeletes;

    public const SIGNATURE_DISK = 'signatures';

    protected static function booted(): void
    {
        static::saving(function (SignatoryProfile $profile): void {
            if ($profile->user_id !== null && ! DB::table('organization_members')
                ->where('organization_id', $profile->organization_id)
                ->where('user_id', $profile->user_id)
                ->exists()) {
                throw new LogicException('A signatory profile user must belong to its organization.');
            }

            if ($profile->academic_unit_id !== null && ! AcademicUnit::query()
                ->where('organization_id', $profile->organization_id)
                ->whereKey($profile->academic_unit_id)
                ->exists()) {
                throw new LogicException('A signatory profile academic unit must belong to its organization.');
            }

            if ($profile->valid_from !== null
                && $profile->valid_until !== null
                && $profile->valid_from->greaterThan($profile->valid_until)) {
                throw new LogicException('A signatory profile must end on or after its start date.');
            }

            $hasSignatureMetadata = $profile->signature_disk !== null
                || $profile->signature_path !== null
                || $profile->signature_checksum !== null;

            if ($hasSignatureMetadata
                && ($profile->signature_disk === null
                    || $profile->signature_path === null
                    || $profile->signature_checksum === null)) {
                throw new LogicException('A signatory signature asset requires a disk, path, and checksum.');
            }

            if ($profile->signature_disk !== null && $profile->signature_disk !== self::SIGNATURE_DISK) {
                throw new LogicException('Signatory signature assets must use the private signatures disk.');
            }

            if ($profile->signature_checksum !== null
                && (strlen($profile->signature_checksum) !== 64
                    || ! ctype_xdigit($profile->signature_checksum))) {
                throw new LogicException('Signatory signature checksums must be SHA-256 hex digests.');
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<AcademicUnit, $this> */
    public function academicUnit(): BelongsTo
    {
        return $this->belongsTo(AcademicUnit::class);
    }

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }
}
