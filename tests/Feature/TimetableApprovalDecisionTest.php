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
use App\Models\AcademicYear;
use App\Models\Organization;
use App\Models\ScheduleEntry;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function createApprovalWorkflowVersion(Organization $organization, bool $allowSelfApproval = false, int $stepCount = 1): int
{
    $timestamp = now();
    $workflowId = DB::table('approval_workflows')->insertGetId([
        'organization_id' => $organization->id,
        'public_id' => (string) Str::uuid(),
        'name' => 'Standard review',
        'is_active' => true,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    $workflowVersionId = DB::table('approval_workflow_versions')->insertGetId([
        'organization_id' => $organization->id,
        'approval_workflow_id' => $workflowId,
        'version_number' => 1,
        'activated_at' => $timestamp,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    foreach (range(1, $stepCount) as $sequence) {
        DB::table('approval_workflow_steps')->insert([
            'organization_id' => $organization->id,
            'approval_workflow_version_id' => $workflowVersionId,
            'sequence' => $sequence,
            'label' => 'Scheduling review '.$sequence,
            'approver_selector_type' => 'permission',
            'required_permission' => OrganizationPermission::ManageScheduling->value,
            'minimum_approvals' => 1,
            'allow_self_approval' => $allowSelfApproval,
            'signatory_slot' => 'registrar',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    return $workflowVersionId;
}

function createApprovalVersion(Organization $organization, User $creator): TimetableVersion
{
    $year = AcademicYear::factory()->forOrganization($organization)->create([
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
        'signature_disk' => 'private',
        'signature_path' => 'signatures/approver.png',
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
        ->and($action->signature_disk)->toBe('private')
        ->and($action->signature_path)->toBe('signatures/approver.png')
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
