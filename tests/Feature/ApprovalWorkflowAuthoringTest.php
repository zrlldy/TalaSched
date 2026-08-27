<?php

use App\Approvals\ActivateApprovalWorkflowVersion;
use App\Approvals\CreateApprovalWorkflowVersion;
use App\Approvals\RetireApprovalWorkflow;
use App\Approvals\SubmitTimetableForApproval;
use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Models\AcademicPeriod;
use App\Models\AcademicUnit;
use App\Models\AcademicYear;
use App\Models\Timetable;
use App\Models\TimetableVersion;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

function approvalWorkflowSteps(?AcademicUnit $academicUnit = null): array
{
    return [
        [
            'sequence' => 1,
            'label' => 'Scheduling review',
            'academic_unit_public_id' => $academicUnit?->public_id,
            'approver_selector_type' => 'permission',
            'required_permission' => OrganizationPermission::ManageScheduling->value,
            'minimum_approvals' => 1,
            'allow_self_approval' => false,
            'signatory_slot' => 'registrar',
        ],
        [
            'sequence' => 2,
            'label' => 'Administrative review',
            'academic_unit_public_id' => $academicUnit?->public_id,
            'approver_selector_type' => 'role',
            'role_codes' => [OrganizationRole::Admin->value],
            'minimum_approvals' => 1,
            'allow_self_approval' => false,
            'signatory_slot' => 'dean',
        ],
    ];
}

beforeEach(function () {
    $this->owner = User::factory()->withOwnedOrganization()->create();
    $this->organization = $this->owner->currentOrganization;
    grantApprovalWorkflowsEntitlement($this->organization);
});

test('workflow authoring creates immutable versions and snapshots role selectors', function () {
    $academicUnit = AcademicUnit::factory()->forOrganization($this->organization)->create();
    $action = app(CreateApprovalWorkflowVersion::class);
    $firstVersionId = $action->handle(
        $this->organization,
        $this->owner,
        'Academic review',
        approvalWorkflowSteps($academicUnit),
        requireDistinctApprovers: true,
    );
    $secondVersionId = $action->handle($this->organization, $this->owner, 'Academic review', approvalWorkflowSteps());

    $workflow = DB::table('approval_workflows')->where('organization_id', $this->organization->id)->first();
    $roleStep = DB::table('approval_workflow_steps')
        ->where('approval_workflow_version_id', $firstVersionId)
        ->where('sequence', 2)
        ->first();

    expect($firstVersionId)->not->toBe($secondVersionId)
        ->and(DB::table('approval_workflows')->where('organization_id', $this->organization->id)->count())->toBe(1)
        ->and((bool) $workflow->is_active)->toBeFalse()
        ->and(DB::table('approval_workflow_versions')->where('approval_workflow_id', $workflow->id)->count())->toBe(2)
        ->and((bool) DB::table('approval_workflow_versions')->where('id', $firstVersionId)->value('require_distinct_approvers'))->toBeTrue()
        ->and((bool) DB::table('approval_workflow_versions')->where('id', $secondVersionId)->value('require_distinct_approvers'))->toBeFalse()
        ->and(DB::table('approval_workflow_steps')->where('id', DB::table('approval_workflow_steps')->where('approval_workflow_version_id', $firstVersionId)->where('sequence', 1)->value('id'))->value('academic_unit_id'))->toBe($academicUnit->id)
        ->and(DB::table('approval_step_roles')->where('approval_workflow_step_id', $roleStep->id)->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'approval_workflow.version_created')->count())->toBe(2);
});

test('workflow authoring rejects foreign and deleted academic-unit targets', function () {
    $foreignOwner = User::factory()->withOwnedOrganization()->create();
    $foreignUnit = AcademicUnit::factory()->forOrganization($foreignOwner->currentOrganization)->create();

    expect(fn () => app(CreateApprovalWorkflowVersion::class)->handle(
        $this->organization,
        $this->owner,
        'Foreign academic review',
        approvalWorkflowSteps($foreignUnit),
    ))->toThrow(InvalidArgumentException::class, 'active organization unit');

    $deletedUnit = AcademicUnit::factory()->forOrganization($this->organization)->create();
    $deletedUnit->delete();

    expect(fn () => app(CreateApprovalWorkflowVersion::class)->handle(
        $this->organization,
        $this->owner,
        'Deleted academic review',
        approvalWorkflowSteps($deletedUnit),
    ))->toThrow(InvalidArgumentException::class, 'active organization unit');
});

test('workflow activation validates steps and retirement disables future use', function () {
    $versionId = app(CreateApprovalWorkflowVersion::class)->handle(
        $this->organization,
        $this->owner,
        'Academic review',
        approvalWorkflowSteps(),
    );
    $workflowId = DB::table('approval_workflow_versions')->where('id', $versionId)->value('approval_workflow_id');

    app(ActivateApprovalWorkflowVersion::class)->handle($this->organization, $this->owner, $versionId);
    app(ActivateApprovalWorkflowVersion::class)->handle($this->organization, $this->owner, $versionId);

    expect(DB::table('approval_workflow_versions')->where('id', $versionId)->value('activated_at'))->not->toBeNull()
        ->and(DB::table('audit_events')->where('action', 'approval_workflow.version_activated')->count())->toBe(1);

    app(RetireApprovalWorkflow::class)->handle($this->organization, $this->owner, $workflowId);

    expect((bool) DB::table('approval_workflows')->where('id', $workflowId)->value('is_active'))->toBeFalse()
        ->and(DB::table('audit_events')->where('action', 'approval_workflow.retired')->count())->toBe(1);
});

test('workflow activation rejects malformed persisted steps without activating them', function () {
    $versionId = app(CreateApprovalWorkflowVersion::class)->handle(
        $this->organization,
        $this->owner,
        'Academic review',
        approvalWorkflowSteps(),
    );
    DB::table('approval_workflow_steps')
        ->where('approval_workflow_version_id', $versionId)
        ->where('sequence', 1)
        ->update(['required_permission' => 'not-a-permission']);

    expect(fn () => app(ActivateApprovalWorkflowVersion::class)->handle($this->organization, $this->owner, $versionId))
        ->toThrow(InvalidArgumentException::class);
    expect(DB::table('approval_workflow_versions')->where('id', $versionId)->value('activated_at'))->toBeNull();
});

test('submission rejects draft or retired workflow versions', function () {
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
        'Academic review',
        approvalWorkflowSteps(),
    );

    expect(fn () => app(SubmitTimetableForApproval::class)->handle($version, $workflowVersionId, $this->owner))
        ->toThrow(DomainException::class, 'Only an activated approval workflow version may be submitted.');

    $workflowId = DB::table('approval_workflow_versions')->where('id', $workflowVersionId)->value('approval_workflow_id');
    app(ActivateApprovalWorkflowVersion::class)->handle($this->organization, $this->owner, $workflowVersionId);
    app(RetireApprovalWorkflow::class)->handle($this->organization, $this->owner, $workflowId);

    expect(fn () => app(SubmitTimetableForApproval::class)->handle($version, $workflowVersionId, $this->owner))
        ->toThrow(DomainException::class, 'The approval workflow is retired.');
});

test('workflow authoring requires membership permission and capability', function () {
    $member = User::factory()->create();
    $this->organization->members()->attach($member, ['role' => OrganizationRole::Member]);

    expect(fn () => app(CreateApprovalWorkflowVersion::class)->handle(
        $this->organization,
        $member,
        'Academic review',
        approvalWorkflowSteps(),
    ))->toThrow(AuthorizationException::class);

    DB::table('organization_subscriptions')
        ->where('organization_id', $this->organization->id)
        ->update(['period_ends_at' => now()->subMinute()]);

    expect(fn () => app(CreateApprovalWorkflowVersion::class)->handle(
        $this->organization,
        $this->owner,
        'Academic review',
        approvalWorkflowSteps(),
    ))->toThrow(AuthorizationException::class);
});
