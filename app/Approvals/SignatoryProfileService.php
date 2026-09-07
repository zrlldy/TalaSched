<?php

namespace App\Approvals;

use App\Audit\AuditLogger;
use App\Models\AcademicUnit;
use App\Models\Organization;
use App\Models\SignatoryProfile;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SignatoryProfileService
{
    public function __construct(
        private TenantContext $tenantContext,
        private ApprovalWorkflowAuthorizer $authorizer,
        private AuditLogger $auditLogger,
    ) {}

    public function create(
        Organization $organization,
        User $actor,
        User $signatory,
        string $name,
        string $position,
        ?AcademicUnit $academicUnit = null,
        ?CarbonInterface $validFrom = null,
        ?CarbonInterface $validUntil = null,
        ?UploadedFile $signatureImage = null,
    ): SignatoryProfile {
        $this->assertValidText($name, 'name', 'A signatory name is required.');
        $this->assertValidText($position, 'position', 'A signatory position is required.');
        $this->assertValidDates($validFrom, $validUntil);
        $this->validateSignatureImage($signatureImage);

        return $this->tenantContext->run($organization, function () use ($organization, $actor, $signatory, $name, $position, $academicUnit, $validFrom, $validUntil, $signatureImage): SignatoryProfile {
            $this->authorizer->authorize($organization, $actor);
            $this->assertSignatoryBelongsToOrganization($organization, $signatory);
            $resolvedAcademicUnit = $this->academicUnit($organization, $academicUnit);
            $asset = $signatureImage === null
                ? null
                : $this->storeSignatureImage($organization, $signatureImage);
            $committed = false;

            try {
                $profile = DB::transaction(function () use ($organization, $actor, $signatory, $name, $position, $resolvedAcademicUnit, $validFrom, $validUntil, $asset): SignatoryProfile {
                    $this->lockOrganization($organization);
                    $profile = new SignatoryProfile([
                        'organization_id' => $organization->getKey(),
                        'user_id' => $signatory->getKey(),
                        'academic_unit_id' => $resolvedAcademicUnit?->getKey(),
                        'name' => Str::squish($name),
                        'position' => Str::squish($position),
                        'academic_unit_name' => $resolvedAcademicUnit?->name,
                        'valid_from' => $validFrom,
                        'valid_until' => $validUntil,
                    ]);
                    $this->applyAsset($profile, $asset);
                    $profile->save();

                    $this->auditLogger->record(
                        action: 'signatory_profile.created',
                        organization: $organization,
                        actor: $actor,
                        subject: $profile,
                        after: $this->snapshot($profile),
                    );

                    return $profile->fresh(['user', 'academicUnit']);
                }, attempts: 3);
                $committed = true;

                return $profile;
            } finally {
                if (! $committed && $asset !== null) {
                    Storage::disk(SignatoryProfile::SIGNATURE_DISK)->delete($asset['path']);
                }
            }
        });
    }

    public function update(
        Organization $organization,
        User $actor,
        SignatoryProfile $profile,
        string $name,
        string $position,
        ?AcademicUnit $academicUnit = null,
        ?CarbonInterface $validFrom = null,
        ?CarbonInterface $validUntil = null,
        ?UploadedFile $signatureImage = null,
    ): SignatoryProfile {
        $this->assertValidText($name, 'name', 'A signatory name is required.');
        $this->assertValidText($position, 'position', 'A signatory position is required.');
        $this->assertValidDates($validFrom, $validUntil);
        $this->validateSignatureImage($signatureImage);

        return $this->tenantContext->run($organization, function () use ($organization, $actor, $profile, $name, $position, $academicUnit, $validFrom, $validUntil, $signatureImage): SignatoryProfile {
            $this->authorizer->authorize($organization, $actor);
            $resolvedAcademicUnit = $this->academicUnit($organization, $academicUnit);
            $asset = $signatureImage === null
                ? null
                : $this->storeSignatureImage($organization, $signatureImage);
            $committed = false;

            try {
                $updatedProfile = DB::transaction(function () use ($organization, $actor, $profile, $name, $position, $resolvedAcademicUnit, $validFrom, $validUntil, $asset): SignatoryProfile {
                    $this->lockOrganization($organization);
                    $lockedProfile = SignatoryProfile::query()
                        ->whereKey($profile->getKey())
                        ->where('organization_id', $organization->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();
                    $before = $this->snapshot($lockedProfile);
                    $oldSignature = $this->signatureAsset($lockedProfile);

                    $lockedProfile->fill([
                        'name' => Str::squish($name),
                        'position' => Str::squish($position),
                        'academic_unit_id' => $resolvedAcademicUnit?->getKey(),
                        'academic_unit_name' => $resolvedAcademicUnit?->name,
                        'valid_from' => $validFrom,
                        'valid_until' => $validUntil,
                    ]);
                    $this->applyAsset($lockedProfile, $asset);
                    $lockedProfile->save();

                    if ($asset !== null && $oldSignature !== null && $oldSignature['path'] !== $asset['path']) {
                        DB::afterCommit(function () use ($organization, $oldSignature): void {
                            if (! $this->isApprovalSignatureSnapshot($organization, $oldSignature)) {
                                Storage::disk(SignatoryProfile::SIGNATURE_DISK)->delete($oldSignature['path']);
                            }
                        });
                    }

                    $this->auditLogger->record(
                        action: 'signatory_profile.updated',
                        organization: $organization,
                        actor: $actor,
                        subject: $lockedProfile,
                        before: $before,
                        after: $this->snapshot($lockedProfile),
                    );

                    return $lockedProfile->fresh(['user', 'academicUnit']);
                }, attempts: 3);
                $committed = true;

                return $updatedProfile;
            } finally {
                if (! $committed && $asset !== null) {
                    Storage::disk(SignatoryProfile::SIGNATURE_DISK)->delete($asset['path']);
                }
            }
        });
    }

    /**
     * @param  array{path: string, checksum: string}|null  $asset
     */
    private function applyAsset(SignatoryProfile $profile, ?array $asset): void
    {
        if ($asset === null) {
            return;
        }

        $profile->signature_disk = SignatoryProfile::SIGNATURE_DISK;
        $profile->signature_path = $asset['path'];
        $profile->signature_checksum = $asset['checksum'];
    }

    /** @return array{checksum: string, path: string}|null */
    private function signatureAsset(SignatoryProfile $profile): ?array
    {
        if (! is_string($profile->signature_path) || ! is_string($profile->signature_checksum)) {
            return null;
        }

        return [
            'checksum' => $profile->signature_checksum,
            'path' => $profile->signature_path,
        ];
    }

    /** @param array{checksum: string, path: string} $asset */
    private function isApprovalSignatureSnapshot(Organization $organization, array $asset): bool
    {
        return DB::table('approval_actions')
            ->where('organization_id', $organization->getKey())
            ->where('signature_disk', SignatoryProfile::SIGNATURE_DISK)
            ->where('signature_path', $asset['path'])
            ->where('signature_checksum', $asset['checksum'])
            ->exists();
    }

    /**
     * @return array{path: string, checksum: string}
     */
    private function storeSignatureImage(Organization $organization, UploadedFile $signatureImage): array
    {
        $realPath = $signatureImage->getRealPath();
        $checksum = $realPath === false ? false : hash_file('sha256', $realPath);

        if ($checksum === false) {
            throw new RuntimeException('The signatory signature image could not be checksummed.');
        }

        $extension = Str::lower($signatureImage->extension());
        $path = Storage::disk(SignatoryProfile::SIGNATURE_DISK)->putFileAs(
            'organizations/'.Str::lower($organization->public_id).'/signatures',
            $signatureImage,
            Str::uuid().'.'.$extension,
        );

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('The signatory signature image could not be stored.');
        }

        return [
            'path' => $path,
            'checksum' => $checksum,
        ];
    }

    private function validateSignatureImage(?UploadedFile $signatureImage): void
    {
        if ($signatureImage === null) {
            return;
        }

        Validator::make(
            ['signature_image' => $signatureImage],
            [
                'signature_image' => [
                    'required',
                    'file',
                    'image',
                    'mimes:jpg,jpeg,png,webp',
                    'mimetypes:image/jpeg,image/png,image/webp',
                    'max:2048',
                    'dimensions:max_width=4096,max_height=4096',
                ],
            ],
        )->validate();
    }

    private function academicUnit(Organization $organization, ?AcademicUnit $academicUnit): ?AcademicUnit
    {
        if ($academicUnit === null) {
            return null;
        }

        return AcademicUnit::query()
            ->whereKey($academicUnit->getKey())
            ->where('organization_id', $organization->getKey())
            ->firstOrFail();
    }

    private function assertSignatoryBelongsToOrganization(Organization $organization, User $signatory): void
    {
        if (! $signatory->belongsToOrganization($organization)) {
            throw ValidationException::withMessages([
                'user_id' => 'The signatory must be a member of this organization.',
            ]);
        }
    }

    private function assertValidText(string $value, string $field, string $message): void
    {
        if (Str::squish($value) === '') {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    private function assertValidDates(?CarbonInterface $validFrom, ?CarbonInterface $validUntil): void
    {
        if ($validFrom !== null && $validUntil !== null && $validFrom->greaterThan($validUntil)) {
            throw ValidationException::withMessages([
                'valid_until' => 'The signature validity end date must be on or after its start date.',
            ]);
        }
    }

    private function lockOrganization(Organization $organization): void
    {
        Organization::query()
            ->whereKey($organization->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(SignatoryProfile $profile): array
    {
        return [
            'id' => $profile->public_id,
            'user_id' => $profile->user_id,
            'academic_unit_id' => $profile->academic_unit_id,
            'name' => $profile->name,
            'position' => $profile->position,
            'valid_from' => $profile->valid_from?->toDateString(),
            'valid_until' => $profile->valid_until?->toDateString(),
            'signature_checksum' => $profile->signature_checksum,
            'has_signature_asset' => $profile->signature_path !== null,
        ];
    }
}
