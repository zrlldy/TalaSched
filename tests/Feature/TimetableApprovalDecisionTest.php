<?php

use App\Approvals\DecideTimetableApproval;
use App\Approvals\SubmitTimetableForApproval;
use App\Enums\ApprovalDecision;
use App\Enums\ApprovalInstanceStatus;
use App\Enums\ApprovalInstanceStepStatus;
use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Enums\TimetableVersionStatus;
use App\Exceptions\ScheduleConflictException;
use App\Models\AcademicPeriod;
use App\Models\AcademicUnit;
use App\Models\AcademicUnitClosure;
use App\Models\AcademicUnitType;
use App\Models\AcademicYear;
use App\Models\Membership;
use App\Models\MembershipRoleAssignment;
use App\Models\Organization;
use App\Models\ScheduleEntry;
use App\Models\SignatoryProfile;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function createApprovalWorkflowVersion(
    Organization $organization,
    bool $allowSelfApproval = false,
    int $stepCount = 1,
    bool $requireDistinctApprovers = false,
    ?int $academicUnitId = null,
    string $selectorType = 'permission',
): int {
    $timestamp = now();
    $workflowId = DB::table('approval_workflows')->insertGetId([
        'organization_id' => $organization->id,
        'public_id' => (string) Str::uuid(),
        'name' => 'Standard review '.Str::uuid(),
        'is_active' => true,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    $workflowVersionId = DB::table('approval_workflow_versions')->insertGetId([
        'organization_id' => $organization->id,
        'approval_workflow_id' => $workflowId,
        'require_distinct_approvers' => $requireDistinctApprovers,
        'version_number' => 1,
        'activated_at' => $timestamp,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    $roleId = $selectorType === 'role'
        ? (int) DB::table('roles')
            ->where('organization_id', $organization->id)
            ->where('code', OrganizationRole::Admin->value)
            ->value('id')
        : null;

    foreach (range(1, $stepCount) as $sequence) {
        $stepId = DB::table('approval_workflow_steps')->insertGetId([
            'organization_id' => $organization->id,
            'approval_workflow_version_id' => $workflowVersionId,
            'sequence' => $sequence,
            'label' => 'Scheduling review '.$sequence,
            'academic_unit_id' => $academicUnitId,
            'approver_selector_type' => $selectorType,
            'required_permission' => $selectorType === 'permission' ? OrganizationPermission::ManageScheduling->value : null,
            'minimum_approvals' => 1,
            'allow_self_approval' => $allowSelfApproval,
            'signatory_slot' => 'registrar',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        if ($roleId !== null) {
            DB::table('approval_step_roles')->insert([
                'organization_id' => $organization->id,
                'approval_workflow_step_id' => $stepId,
                'role_id' => $roleId,
            ]);
        }
    }

    return $workflowVersionId;
}

function createApprovalVersion(Organization $organization, User $creator): TimetableVersion
{
    $year = AcademicYear::factory()->forOrganization($organization)->create([
        'name' => 'Approval year '.Str::uuid(),
        'status' => 'active',
    ]);
    $period = AcademicPeriod::factory()->forAcademicYear($year)->create();
    $timetable = Timetable::factory()->create([
        'organization_id' => $organization->id,
        'academic_year_id' => $year->id,
        'academic_period_id' => $period->id,
    ]);

    return TimetableVersion::factory()->create([
        'organization_id' => $organization->id,
        'timetable_id' => $timetable->id,
        'created_by' => $creator->id,
    ]);
}

function addApprovalEntitledApprover(Organization $organization): User
{
    $approver = User::factory()->create();
    $organization->members()->attach($approver, ['role' => OrganizationRole::Admin]);

    return $approver;
}

/**
 * @return array{root: AcademicUnit, child: AcademicUnit, sibling: AcademicUnit}
 */
function createApprovalAcademicUnitScope(Organization $organization): array
{
    $unitType = AcademicUnitType::factory()->forOrganization($organization)->create();
    $root = AcademicUnit::factory()->forType($unitType)->create();
    $child = AcademicUnit::factory()->forType($unitType)->create(['parent_id' => $root->id]);
    $sibling = AcademicUnit::factory()->forType($unitType)->create();

    AcademicUnitClosure::query()->insert([
        ['organization_id' => $organization->id, 'ancestor_id' => $root->id, 'descendant_id' => $root->id, 'depth' => 0],
        ['organization_id' => $organization->id, 'ancestor_id' => $root->id, 'descendant_id' => $child->id, 'depth' => 1],
        ['organization_id' => $organization->id, 'ancestor_id' => $child->id, 'descendant_id' => $child->id, 'depth' => 0],
        ['organization_id' => $organization->id, 'ancestor_id' => $sibling->id, 'descendant_id' => $sibling->id, 'depth' => 0],
    ]);

    return compact('root', 'child', 'sibling');
}

beforeEach(function () {
    $this->submitter = User::factory()->withOwnedOrganization()->create();
    $this->organization = $this->submitter->currentOrganization;
    grantApprovalWorkflowsEntitlement($this->organization);
    $this->approver = addApprovalEntitledApprover($this->organization);
});

test('the final approval is authorized, idempotent, audited, and completes the version', function () {
    $version = createApprovalVersion($this->organization, $this->submitter);
    $workflowVersionId = createApprovalWorkflowVersion($this->organization);
    $instanceId = app(SubmitTimetableForApproval::class)->handle($version, $workflowVersionId, $this->submitter);

    $profileId = DB::table('signatory_profiles')->insertGetId([
        'organization_id' => $this->organization->id,
        'user_id' => $this->approver->id,
        'public_id' => (string) Str::uuid(),
        'name' => 'Approver Name',
        'position' => 'Registrar',
        'academic_unit_name' => 'Main Campus',
        'signature_disk' => SignatoryProfile::SIGNATURE_DISK,
        'signature_path' => 'organizations/approver/signatures/approver.png',
        'signature_checksum' => hash('sha256', 'signature'),
        'valid_from' => now()->toDateString(),
        'valid_until' => now()->addYear()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $actionId = app(DecideTimetableApproval::class)->handle(
        $instanceId,
        ApprovalDecision::Approve,
        'approval-final-1',
        $this->approver,
        'Approved for publication.',
    );

    expect($actionId)->toBeInt()
        ->and(app(DecideTimetableApproval::class)->handle(
            $instanceId,
            ApprovalDecision::Approve,
            'approval-final-1',
            $this->approver,
        ))->toBe($actionId);

    $action = DB::table('approval_actions')->where('id', $actionId)->first();

    expect($action->signatory_name)->toBe('Approver Name')
        ->and($action->signatory_position)->toBe('Registrar')
        ->and($action->signatory_academic_unit)->toBe('Main Campus')
        ->and($action->signature_disk)->toBe(SignatoryProfile::SIGNATURE_DISK)
        ->and($action->signature_path)->toBe('organizations/approver/signatures/approver.png')
        ->and($action->signature_checksum)->toBe(hash('sha256', 'signature'))
        ->and($action->idempotency_key)->toBe('approval-final-1')
        ->and($profileId)->toBeInt()
        ->and(TimetableVersion::query()->findOrFail($version->id)->status)->toBe(TimetableVersionStatus::Approved)
        ->and(DB::table('approval_instances')->where('id', $instanceId)->value('status'))
        ->toBe(ApprovalInstanceStatus::Approved->value)
        ->and(DB::table('approval_instance_steps')->where('approval_instance_id', $instanceId)->value('status'))
        ->toBe(ApprovalInstanceStepStatus::Approved->value)
        ->and(DB::table('audit_events')->where('action', 'approval.decision_recorded')->count())->toBe(1)
        ->and(DB::table('approval_actions')->where('approval_instance_step_id', $action->approval_instance_step_id)->count())
        ->toBe(1);
});

test('approval decisions ignore signatory profiles outside their validity window', function () {
    $version = createApprovalVersion($this->organization, $this->submitter);
    $workflowVersionId = createApprovalWorkflowVersion($this->organization);
    $instanceId = app(SubmitTimetableForApproval::class)->handle($version, $workflowVersionId, $this->submitter);

    DB::table('signatory_profiles')->insert([
        'organization_id' => $this->organization->id,
        'user_id' => $this->approver->id,
        'public_id' => (string) Str::uuid(),
        'name' => 'Expired Approver',
        'position' => 'Registrar',
        'signature_disk' => 'signatures',
        'signature_path' => 'organizations/expired/signatures/profile.png',
        'signature_checksum' => hash('sha256', 'expired-signature'),
        'valid_from' => now()->subMonth()->toDateString(),
        'valid_until' => now()->subDay()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(DecideTimetableApproval::class)->handle(
        $instanceId,
        ApprovalDecision::Approve,
        'approval-expired-signatory',
        $this->approver,
    );

    $action = DB::table('approval_actions')->where('idempotency_key', 'approval-expired-signatory')->first();

    expect($action->signatory_name)->toBeNull()
        ->and($action->signature_path)->toBeNull()
        ->and($version->fresh()->status)->toBe(TimetableVersionStatus::Approved);
});

test('final approval reruns hard validation and rolls back the decision when invalid', function () {
    $version = createApprovalVersion($this->organization, $this->submitter);
    ScheduleEntry::factory()->create([
        'organization_id' => $this->organization->id,
        'timetable_version_id' => $version->id,
    ]);
    $workflowVersionId = createApprovalWorkflowVersion($this->organization);
    $instanceId = app(SubmitTimetableForApproval::class)->handle($version, $workflowVersionId, $this->submitter);

    expect(fn () => app(DecideTimetableApproval::class)->handle(
        $instanceId,
        ApprovalDecision::Approve,
        'approval-invalid-1',
        $this->approver,
    ))->toThrow(ScheduleConflictException::class);

    expect($version->fresh()->status)->toBe(TimetableVersionStatus::InReview)
        ->and(DB::table('approval_instances')->where('id', $instanceId)->value('status'))
        ->toBe(ApprovalInstanceStatus::Pending->value)
        ->and(DB::table('approval_actions')->where('idempotency_key', 'approval-invalid-1')->exists())
        ->toBeFalse();
});

test('requesting changes returns the version to editable state and permits resubmission', function () {
    $version = createApprovalVersion($this->organization, $this->submitter);
    $workflowVersionId = createApprovalWorkflowVersion($this->organization);
    $instanceId = app(SubmitTimetableForApproval::class)->handle($version, $workflowVersionId, $this->submitter);

    app(DecideTimetableApproval::class)->handle(
        $instanceId,
        ApprovalDecision::RequestChanges,
        'approval-changes-1',
        $this->approver,
        'Please revise the resource assignment.',
    );

    $resubmittedInstanceId = app(SubmitTimetableForApproval::class)->handle(
        $version->fresh(),
        $workflowVersionId,
        $this->submitter,
    );

    expect($resubmittedInstanceId)->not->toBe($instanceId)
        ->and($version->fresh()->status)->toBe(TimetableVersionStatus::InReview)
        ->and(DB::table('approval_instances')->where('timetable_version_id', $version->id)->count())->toBe(2)
        ->and(DB::table('approval_instances')->where('id', $instanceId)->value('status'))
        ->toBe(ApprovalInstanceStatus::ChangesRequested->value);
});

test('approval advances through every ordered step before completing the version', function () {
    $secondApprover = addApprovalEntitledApprover($this->organization);
    $version = createApprovalVersion($this->organization, $this->submitter);
    $workflowVersionId = createApprovalWorkflowVersion($this->organization, stepCount: 2);
    $instanceId = app(SubmitTimetableForApproval::class)->handle($version, $workflowVersionId, $this->submitter);

    app(DecideTimetableApproval::class)->handle(
        $instanceId,
        ApprovalDecision::Approve,
        'approval-step-1',
        $this->approver,
    );

    expect(DB::table('approval_instances')->where('id', $instanceId)->value('status'))
        ->toBe(ApprovalInstanceStatus::Pending->value)
        ->and(DB::table('approval_instance_steps')->where('approval_instance_id', $instanceId)->where('sequence', 1)->value('status'))
        ->toBe(ApprovalInstanceStepStatus::Approved->value)
        ->and(DB::table('approval_instance_steps')->where('approval_instance_id', $instanceId)->where('sequence', 2)->value('status'))
        ->toBe(ApprovalInstanceStepStatus::Active->value)
        ->and($version->fresh()->status)->toBe(TimetableVersionStatus::InReview);

    app(DecideTimetableApproval::class)->handle(
        $instanceId,
        ApprovalDecision::Approve,
        'approval-step-2',
        $secondApprover,
    );

    expect(DB::table('approval_instances')->where('id', $instanceId)->value('status'))
        ->toBe(ApprovalInstanceStatus::Approved->value)
        ->and($version->fresh()->status)->toBe(TimetableVersionStatus::Approved);
});

test('a workflow can require distinct approvers across sequential steps', function () {
    $secondApprover = addApprovalEntitledApprover($this->organization);
    $version = createApprovalVersion($this->organization, $this->submitter);
    $workflowVersionId = createApprovalWorkflowVersion(
        $this->organization,
        stepCount: 2,
        requireDistinctApprovers: true,
    );
    $instanceId = app(SubmitTimetableForApproval::class)->handle($version, $workflowVersionId, $this->submitter);

    app(DecideTimetableApproval::class)->handle(
        $instanceId,
        ApprovalDecision::Approve,
        'approval-distinct-step-1',
        $this->approver,
    );

    expect(fn () => app(DecideTimetableApproval::class)->handle(
        $instanceId,
        ApprovalDecision::Approve,
        'approval-distinct-step-2-rejected',
        $this->approver,
    ))->toThrow(DomainException::class, 'distinct approvers')
        ->and(DB::table('approval_actions')
            ->join('approval_instance_steps', 'approval_instance_steps.id', '=', 'approval_actions.approval_instance_step_id')
            ->where('approval_instance_steps.approval_instance_id', $instanceId)
            ->count())->toBe(1);

    app(DecideTimetableApproval::class)->handle(
        $instanceId,
        ApprovalDecision::Approve,
        'approval-distinct-step-2',
        $secondApprover,
    );

    expect($version->fresh()->status)->toBe(TimetableVersionStatus::Approved);
});

test('permission eligibility respects academic-unit scope and immutable instance snapshots', function () {
    $units = createApprovalAcademicUnitScope($this->organization);
    $membership = Membership::query()
        ->where('organization_id', $this->organization->id)
        ->where('user_id', $this->approver->id)
        ->firstOrFail();
    $adminRole = $this->organization->roles()
        ->where('code', OrganizationRole::Admin->value)
        ->firstOrFail();

    MembershipRoleAssignment::query()
        ->where('membership_id', $membership->getKey())
        ->delete();
    MembershipRoleAssignment::query()->create([
        'organization_id' => $this->organization->id,
        'membership_id' => $membership->getKey(),
        'role_id' => $adminRole->getKey(),
        'academic_unit_id' => $units['root']->id,
    ]);

    $childVersion = createApprovalVersion($this->organization, $this->submitter);
    $childWorkflowVersionId = createApprovalWorkflowVersion(
        $this->organization,
        academicUnitId: $units['child']->id,
    );
    $childInstanceId = app(SubmitTimetableForApproval::class)->handle(
        $childVersion,
        $childWorkflowVersionId,
        $this->submitter,
    );

    expect(DB::table('approval_instance_steps')->where('approval_instance_id', $childInstanceId)->value('academic_unit_id'))
        ->toBe($units['child']->id);

    app(DecideTimetableApproval::class)->handle(
        $childInstanceId,
        ApprovalDecision::Approve,
        'approval-scoped-child',
        $this->approver,
    );

    expect($childVersion->fresh()->status)->toBe(TimetableVersionStatus::Approved);

    $siblingVersion = createApprovalVersion($this->organization, $this->submitter);
    $siblingWorkflowVersionId = createApprovalWorkflowVersion(
        $this->organization,
        academicUnitId: $units['sibling']->id,
    );
    $siblingInstanceId = app(SubmitTimetableForApproval::class)->handle(
        $siblingVersion,
        $siblingWorkflowVersionId,
        $this->submitter,
    );

    expect(fn () => app(DecideTimetableApproval::class)->handle(
        $siblingInstanceId,
        ApprovalDecision::Approve,
        'approval-scoped-sibling',
        $this->approver,
    ))->toThrow(DomainException::class, 'not eligible');

    $organizationVersion = createApprovalVersion($this->organization, $this->submitter);
    $organizationWorkflowVersionId = createApprovalWorkflowVersion($this->organization);
    $organizationInstanceId = app(SubmitTimetableForApproval::class)->handle(
        $organizationVersion,
        $organizationWorkflowVersionId,
        $this->submitter,
    );

    expect(fn () => app(DecideTimetableApproval::class)->handle(
        $organizationInstanceId,
        ApprovalDecision::Approve,
        'approval-scoped-organization',
        $this->approver,
    ))->toThrow(DomainException::class, 'not eligible');
});

test('role eligibility accepts ancestor scopes and rejects unrelated units', function () {
    $units = createApprovalAcademicUnitScope($this->organization);
    $membership = Membership::query()
        ->where('organization_id', $this->organization->id)
        ->where('user_id', $this->approver->id)
        ->firstOrFail();
    $adminRole = $this->organization->roles()
        ->where('code', OrganizationRole::Admin->value)
        ->firstOrFail();

    MembershipRoleAssignment::query()
        ->where('membership_id', $membership->getKey())
        ->delete();
    MembershipRoleAssignment::query()->create([
        'organization_id' => $this->organization->id,
        'membership_id' => $membership->getKey(),
        'role_id' => $adminRole->getKey(),
        'academic_unit_id' => $units['root']->id,
    ]);

    $childVersion = createApprovalVersion($this->organization, $this->submitter);
    $childWorkflowVersionId = createApprovalWorkflowVersion(
        $this->organization,
        academicUnitId: $units['child']->id,
        selectorType: 'role',
    );
    $childInstanceId = app(SubmitTimetableForApproval::class)->handle(
        $childVersion,
        $childWorkflowVersionId,
        $this->submitter,
    );

    app(DecideTimetableApproval::class)->handle(
        $childInstanceId,
        ApprovalDecision::Approve,
        'approval-role-child',
        $this->approver,
    );

    expect($childVersion->fresh()->status)->toBe(TimetableVersionStatus::Approved);

    $siblingVersion = createApprovalVersion($this->organization, $this->submitter);
    $siblingWorkflowVersionId = createApprovalWorkflowVersion(
        $this->organization,
        academicUnitId: $units['sibling']->id,
        selectorType: 'role',
    );
    $siblingInstanceId = app(SubmitTimetableForApproval::class)->handle(
        $siblingVersion,
        $siblingWorkflowVersionId,
        $this->submitter,
    );

    expect(fn () => app(DecideTimetableApproval::class)->handle(
        $siblingInstanceId,
        ApprovalDecision::Approve,
        'approval-role-sibling',
        $this->approver,
    ))->toThrow(DomainException::class, 'not eligible');
});
