<?php

use App\Approvals\ActivateApprovalWorkflowVersion;
use App\Approvals\CreateApprovalWorkflowVersion;
use App\Approvals\SubmitTimetableForApproval;
use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->owner = User::factory()->withOwnedOrganization()->create();
    $this->organization = $this->owner->currentOrganization;
    grantApprovalWorkflowsEntitlement($this->organization);
});

test('the workflow designer creates and activates public-id-addressed versions', function (): void {
    $response = $this->actingAs($this->owner)->post(route('approvals.workflows.store', [
        'current_organization' => $this->organization->slug,
    ]), [
        'name' => 'Academic review',
        'require_distinct_approvers' => '1',
        'steps' => [[
            'label' => 'Scheduling review',
            'academic_unit_id' => '',
            'approver_selector_type' => 'permission',
            'required_permission' => OrganizationPermission::ManageScheduling->value,
            'minimum_approvals' => '1',
            'signatory_slot' => 'registrar',
        ]],
    ]);

    $response->assertRedirect(route('approvals.workflows', [
        'current_organization' => $this->organization->slug,
    ]));
    $workflow = DB::table('approval_workflows')->where('organization_id', $this->organization->id)->firstOrFail();
    $version = DB::table('approval_workflow_versions')->where('approval_workflow_id', $workflow->id)->firstOrFail();

    $this->actingAs($this->owner)->post(route('approvals.workflows.versions.activate', [
        'current_organization' => $this->organization->slug,
        'workflow' => $workflow->public_id,
        'version' => $version->version_number,
    ]))->assertRedirect();

    $debugResponse = $this->actingAs($this->owner)->get(route('approvals.workflows', [
        'current_organization' => $this->organization->slug,
    ]));
    $debugResponse->assertInertia(fn (Assert $page): Assert => $page
        ->component('approvals/Workflows')
        ->where('workflows.0.id', $workflow->public_id)
        ->where('workflows.0.versions.0.version', 1)
        ->where('workflows.0.versions.0.is_active', true)
        ->where('workflows.0.versions.0.steps.0.required_permission', OrganizationPermission::ManageScheduling->value));
});

test('the approval inbox renders a timeline and records a decision by timetable version public id', function (): void {
    $year = AcademicYear::factory()->forOrganization($this->organization)->create(['status' => 'active']);
    $period = AcademicPeriod::factory()->forAcademicYear($year)->create();
    $timetable = Timetable::factory()->create([
        'organization_id' => $this->organization->id,
        'academic_year_id' => $year->id,
        'academic_period_id' => $period->id,
    ]);
    $version = TimetableVersion::factory()->create([
        'organization_id' => $this->organization->id,
        'timetable_id' => $timetable->id,
        'created_by' => $this->owner->id,
    ]);
    $workflowVersionId = app(CreateApprovalWorkflowVersion::class)->handle(
        $this->organization,
        $this->owner,
        'Inbox review',
        [[
            'sequence' => 1,
            'label' => 'Scheduling review',
            'approver_selector_type' => 'permission',
            'required_permission' => OrganizationPermission::ManageScheduling->value,
            'role_codes' => [],
            'minimum_approvals' => 1,
            'allow_self_approval' => false,
            'signatory_slot' => 'registrar',
        ]],
    );
    app(ActivateApprovalWorkflowVersion::class)->handle($this->organization, $this->owner, $workflowVersionId);
    $approver = User::factory()->create();
    $this->organization->members()->attach($approver, ['role' => OrganizationRole::Admin]);
    app(SubmitTimetableForApproval::class)->handle($version, $workflowVersionId, $this->owner);

    $this->actingAs($approver)->get(route('approvals.inbox', [
        'current_organization' => $this->organization->slug,
    ]))->assertInertia(fn (Assert $page): Assert => $page
        ->component('approvals/Inbox')
        ->where('instances.0.id', $version->public_id)
        ->where('instances.0.status', 'pending')
        ->where('instances.0.can_decide', true)
        ->where('instances.0.steps.0.label', 'Scheduling review'));

    $idempotencyKey = (string) Str::uuid();
    $this->actingAs($approver)->post(route('approvals.decide', [
        'current_organization' => $this->organization->slug,
        'timetable_version' => $version->public_id,
    ]), [
        'decision' => 'approve',
        'comment' => 'Ready for publication.',
        'idempotency_key' => $idempotencyKey,
    ])->assertRedirect(route('approvals.inbox', [
        'current_organization' => $this->organization->slug,
    ]));

    expect(DB::table('approval_instances')->where('timetable_version_id', $version->id)->value('status'))
        ->toBe('approved')
        ->and(DB::table('approval_actions')->where('comment', 'Ready for publication.')->exists())
        ->toBeTrue();

    $this->actingAs($approver)->post(route('approvals.decide', [
        'current_organization' => $this->organization->slug,
        'timetable_version' => $version->public_id,
    ]), [
        'decision' => 'approve',
        'idempotency_key' => $idempotencyKey,
    ])->assertRedirect();

    expect(DB::table('approval_actions')->where('idempotency_key', $idempotencyKey)->count())->toBe(1);
});

test('the inbox keeps the immutable workflow version and approver eligibility selected at submission time', function (): void {
    $year = AcademicYear::factory()->forOrganization($this->organization)->create(['status' => 'active']);
    $period = AcademicPeriod::factory()->forAcademicYear($year)->create();
    $timetable = Timetable::factory()->create([
        'organization_id' => $this->organization->id,
        'academic_year_id' => $year->id,
        'academic_period_id' => $period->id,
    ]);
    $version = TimetableVersion::factory()->create([
        'organization_id' => $this->organization->id,
        'timetable_id' => $timetable->id,
        'created_by' => $this->owner->id,
    ]);
    $firstWorkflowVersionId = app(CreateApprovalWorkflowVersion::class)->handle(
        $this->organization,
        $this->owner,
        'Versioned review',
        [[
            'sequence' => 1,
            'label' => 'Original review',
            'approver_selector_type' => 'permission',
            'required_permission' => OrganizationPermission::ManageScheduling->value,
            'role_codes' => [],
            'minimum_approvals' => 1,
            'allow_self_approval' => false,
            'signatory_slot' => 'registrar',
        ]],
    );
    app(ActivateApprovalWorkflowVersion::class)->handle($this->organization, $this->owner, $firstWorkflowVersionId);
    $approver = User::factory()->create();
    $this->organization->members()->attach($approver, ['role' => OrganizationRole::Admin]);
    app(SubmitTimetableForApproval::class)->handle($version, $firstWorkflowVersionId, $this->owner);

    $secondWorkflowVersionId = app(CreateApprovalWorkflowVersion::class)->handle(
        $this->organization,
        $this->owner,
        'Versioned review',
        [[
            'sequence' => 1,
            'label' => 'Replacement review',
            'approver_selector_type' => 'role',
            'required_permission' => null,
            'role_codes' => [OrganizationRole::Member->value],
            'minimum_approvals' => 1,
            'allow_self_approval' => false,
            'signatory_slot' => 'registrar',
        ]],
    );
    app(ActivateApprovalWorkflowVersion::class)->handle($this->organization, $this->owner, $secondWorkflowVersionId);

    $this->actingAs($approver)->get(route('approvals.inbox', [
        'current_organization' => $this->organization->slug,
    ]))->assertInertia(fn (Assert $page): Assert => $page
        ->component('approvals/Inbox')
        ->where('instances.0.workflow_version', 1)
        ->where('instances.0.steps.0.label', 'Original review'));

    $this->actingAs($approver)->post(route('approvals.decide', [
        'current_organization' => $this->organization->slug,
        'timetable_version' => $version->public_id,
    ]), [
        'decision' => 'approve',
        'idempotency_key' => (string) Str::uuid(),
    ])->assertRedirect(route('approvals.inbox', [
        'current_organization' => $this->organization->slug,
    ]));

    expect(DB::table('approval_instances')->where('timetable_version_id', $version->id)->value('status'))
        ->toBe('approved');
});

test('signatory administration stores private assets and exposes only safe profile attributes', function (): void {
    Storage::fake('signatures');
    $membership = $this->organization->memberships()->where('user_id', $this->owner->id)->firstOrFail();

    $this->actingAs($this->owner)->post(route('approvals.signatories.store', [
        'current_organization' => $this->organization->slug,
    ]), [
        'membership_id' => $membership->public_id,
        'name' => 'Registrar Name',
        'position' => 'Registrar',
        'valid_from' => now()->toDateString(),
        'valid_until' => now()->addYear()->toDateString(),
        'signature_image' => UploadedFile::fake()->image('signature.png'),
    ])->assertRedirect(route('approvals.signatories', [
        'current_organization' => $this->organization->slug,
    ]));

    $profile = $this->organization->signatoryProfiles()->firstOrFail();

    Storage::disk('signatures')->assertExists($profile->signature_path);
    $this->actingAs($this->owner)->get(route('approvals.signatories', [
        'current_organization' => $this->organization->slug,
    ]))->assertInertia(fn (Assert $page): Assert => $page
        ->component('approvals/Signatories')
        ->where('profiles.0.id', $profile->public_id)
        ->where('profiles.0.valid_from', now()->toDateString())
        ->where('profiles.0.valid_until', now()->addYear()->toDateString())
        ->where('profiles.0.has_signature', true)
        ->has('profiles.0.signature_download_url')
        ->missing('profiles.0.signature_path')
        ->where('members.0.id', $membership->public_id));
});

test('signatory administration rejects signature images above the pixel dimension limit', function (): void {
    $membership = $this->organization->memberships()->where('user_id', $this->owner->id)->firstOrFail();

    $this->actingAs($this->owner)->post(route('approvals.signatories.store', [
        'current_organization' => $this->organization->slug,
    ]), [
        'membership_id' => $membership->public_id,
        'name' => 'Registrar Name',
        'position' => 'Registrar',
        'signature_image' => UploadedFile::fake()->image('oversized.png', 4097, 1),
    ])->assertSessionHasErrors('signature_image');

    expect($this->organization->signatoryProfiles()->exists())->toBeFalse();
});

test('signatory signature downloads require a current short-lived authorized link', function (): void {
    Storage::fake('signatures');
    $membership = $this->organization->memberships()->where('user_id', $this->owner->id)->firstOrFail();

    $this->actingAs($this->owner)->post(route('approvals.signatories.store', [
        'current_organization' => $this->organization->slug,
    ]), [
        'membership_id' => $membership->public_id,
        'name' => 'Registrar Name',
        'position' => 'Registrar',
        'signature_image' => UploadedFile::fake()->image('signature.png'),
    ])->assertRedirect();

    $profile = $this->organization->signatoryProfiles()->firstOrFail();
    $signatureUrl = URL::temporarySignedRoute('approvals.signatories.signature.download', now()->addMinutes(5), [
        'current_organization' => $this->organization->slug,
        'signatory_profile' => $profile->public_id,
    ]);

    $this->actingAs($this->owner)->get($signatureUrl)
        ->assertDownload('signature-'.$profile->public_id.'.png');

    $unauthorizedMember = User::factory()->create();
    $this->organization->members()->attach($unauthorizedMember, ['role' => OrganizationRole::Member]);

    $this->actingAs($unauthorizedMember)->get($signatureUrl)->assertForbidden();

    $foreignOwner = User::factory()->withOwnedOrganization()->create();
    $foreignOrganization = $foreignOwner->currentOrganization;
    $foreignOrganizationUrl = URL::temporarySignedRoute('approvals.signatories.signature.download', now()->addMinutes(5), [
        'current_organization' => $foreignOrganization->slug,
        'signatory_profile' => $profile->public_id,
    ]);

    $this->actingAs($foreignOwner)->get($foreignOrganizationUrl)->assertNotFound();

    $expiredUrl = URL::temporarySignedRoute('approvals.signatories.signature.download', now()->subMinute(), [
        'current_organization' => $this->organization->slug,
        'signatory_profile' => $profile->public_id,
    ]);

    $this->actingAs($this->owner)->get($expiredUrl)->assertForbidden();
});

test('private artifact disks are not public or framework-served', function (): void {
    foreach (['signatures', 'private'] as $disk) {
        expect(config("filesystems.disks.{$disk}.visibility"))->toBe('private')
            ->and(config("filesystems.disks.{$disk}.serve"))->toBeFalse()
            ->and(config("filesystems.disks.{$disk}.throw"))->toBeTrue();
    }
});

test('workflow and signatory administration require the approval management capability', function (): void {
    $member = User::factory()->create();
    $this->organization->members()->attach($member, ['role' => OrganizationRole::Member]);

    $this->actingAs($member)->get(route('approvals.workflows', [
        'current_organization' => $this->organization->slug,
    ]))->assertForbidden();
});
