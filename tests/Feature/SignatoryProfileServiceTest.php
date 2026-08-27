<?php

use App\Approvals\SignatoryProfileService;
use App\Enums\OrganizationRole;
use App\Models\AcademicUnit;
use App\Models\Organization;
use App\Models\SignatoryProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;

beforeEach(function (): void {
    $this->owner = User::factory()->withOwnedOrganization()->create();
    $this->organization = $this->owner->currentOrganization;
    grantApprovalWorkflowsEntitlement($this->organization);
    $this->signatory = User::factory()->create();
    $this->organization->members()->attach($this->signatory, ['role' => OrganizationRole::Admin]);
    Storage::fake(SignatoryProfile::SIGNATURE_DISK);
});

test('signatory profiles store valid dates and signature images on the private disk', function (): void {
    $academicUnit = AcademicUnit::factory()->forOrganization($this->organization)->create();
    $image = UploadedFile::fake()->image('signature.png', 80, 30);
    $validFrom = CarbonImmutable::parse('2026-08-01');
    $validUntil = CarbonImmutable::parse('2026-12-31');

    $profile = app(SignatoryProfileService::class)->create(
        $this->organization,
        $this->owner,
        $this->signatory,
        '  Dr. Ada   Lovelace  ',
        '  Registrar  ',
        $academicUnit,
        $validFrom,
        $validUntil,
        $image,
    );

    $storedImage = Storage::disk(SignatoryProfile::SIGNATURE_DISK)->get($profile->signature_path);

    $this->assertModelExists($profile);
    Storage::disk(SignatoryProfile::SIGNATURE_DISK)->assertExists($profile->signature_path);

    expect($profile->user->is($this->signatory))->toBeTrue()
        ->and($profile->academicUnit->is($academicUnit))->toBeTrue()
        ->and($profile->academic_unit_name)->toBe($academicUnit->name)
        ->and($profile->name)->toBe('Dr. Ada Lovelace')
        ->and($profile->position)->toBe('Registrar')
        ->and($profile->valid_from->toDateString())->toBe('2026-08-01')
        ->and($profile->valid_until->toDateString())->toBe('2026-12-31')
        ->and($profile->signature_disk)->toBe(SignatoryProfile::SIGNATURE_DISK)
        ->and($profile->signature_path)->toStartWith('organizations/'.$this->organization->public_id.'/signatures/')
        ->and($profile->signature_checksum)->toBe(hash('sha256', $storedImage))
        ->and(DB::table('audit_events')->where('action', 'signatory_profile.created')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'signatory_profile.created')->value('actor_user_id'))
        ->toBe($this->owner->id);
});

test('signatory profiles reject invalid validity windows and foreign references', function (): void {
    $foreignOrganization = Organization::factory()->create();
    $foreignSignatory = User::factory()->create();
    $foreignOrganization->members()->attach($foreignSignatory, ['role' => OrganizationRole::Admin]);
    $foreignUnit = AcademicUnit::factory()->forOrganization($foreignOrganization)->create();

    expect(fn () => app(SignatoryProfileService::class)->create(
        $this->organization,
        $this->owner,
        $this->signatory,
        'Registrar',
        'Registrar',
        null,
        CarbonImmutable::parse('2026-12-31'),
        CarbonImmutable::parse('2026-08-01'),
    ))->toThrow(ValidationException::class, 'end date')
        ->and(fn () => app(SignatoryProfileService::class)->create(
            $this->organization,
            $this->owner,
            $foreignSignatory,
            'Foreign Registrar',
            'Registrar',
        ))->toThrow(ValidationException::class, 'member')
        ->and(fn () => app(SignatoryProfileService::class)->create(
            $this->organization,
            $this->owner,
            $this->signatory,
            'Foreign Unit Registrar',
            'Registrar',
            $foreignUnit,
        ))->toThrow(ModelNotFoundException::class);

    expect(fn () => app(SignatoryProfileService::class)->create(
        $this->organization,
        $this->owner,
        $this->signatory,
        'Invalid Image Registrar',
        'Registrar',
        null,
        null,
        null,
        UploadedFile::fake()->create('signature.txt', 10, 'text/plain'),
    ))->toThrow(ValidationException::class, 'image');
});

test('signatory profile updates replace the private asset and preserve the old snapshot data', function (): void {
    $firstImage = UploadedFile::fake()->image('first.png', 80, 30);
    $profile = app(SignatoryProfileService::class)->create(
        $this->organization,
        $this->owner,
        $this->signatory,
        'Registrar',
        'Registrar',
        null,
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
        $firstImage,
    );
    $oldPath = $profile->signature_path;
    $oldChecksum = $profile->signature_checksum;

    $updated = app(SignatoryProfileService::class)->update(
        $this->organization,
        $this->owner,
        $profile,
        'Senior Registrar',
        'Chief Registrar',
        null,
        CarbonImmutable::parse('2026-09-01'),
        null,
        UploadedFile::fake()->image('second.png', 100, 40),
    );

    Storage::disk(SignatoryProfile::SIGNATURE_DISK)->assertMissing($oldPath);
    Storage::disk(SignatoryProfile::SIGNATURE_DISK)->assertExists($updated->signature_path);

    expect($updated->name)->toBe('Senior Registrar')
        ->and($updated->position)->toBe('Chief Registrar')
        ->and($updated->valid_from->toDateString())->toBe('2026-09-01')
        ->and($updated->valid_until)->toBeNull()
        ->and($updated->signature_checksum)->not->toBe($oldChecksum)
        ->and($updated->signature_path)->not->toBe($oldPath)
        ->and(DB::table('audit_events')->where('action', 'signatory_profile.updated')->count())->toBe(1);
});

test('signatory profile mutations require approval workflow authorization', function (): void {
    $member = User::factory()->create();
    $this->organization->members()->attach($member, ['role' => OrganizationRole::Member]);

    expect(fn () => app(SignatoryProfileService::class)->create(
        $this->organization,
        $member,
        $this->signatory,
        'Unauthorized Registrar',
        'Registrar',
    ))->toThrow(AuthorizationException::class);
});

test('signatory profile model prevents invalid validity and public signature storage', function (): void {
    $profile = app(SignatoryProfileService::class)->create(
        $this->organization,
        $this->owner,
        $this->signatory,
        'Registrar',
        'Registrar',
    );

    expect(fn () => $profile->update([
        'valid_from' => CarbonImmutable::parse('2026-12-31'),
        'valid_until' => CarbonImmutable::parse('2026-08-01'),
    ]))->toThrow(LogicException::class, 'end')
        ->and($profile->refresh())->not->toBeNull();

    $profile->signature_disk = 'public';
    $profile->signature_path = 'signatures/public.png';
    $profile->signature_checksum = hash('sha256', 'signature');

    expect(fn () => $profile->save())->toThrow(LogicException::class, 'private');
});
