<?php

namespace App\Approvals;

use App\Audit\AuditLogger;
use App\Enums\ApprovalDecision;
use App\Enums\ApprovalInstanceStatus;
use App\Enums\ApprovalInstanceStepStatus;
use App\Enums\OrganizationPermission;
use App\Enums\TimetableVersionStatus;
use App\Exceptions\ScheduleConflictException;
use App\Models\Organization;
use App\Models\TimetableVersion;
use App\Models\User;
use App\Scheduling\ValidateTimetableVersion;
use DomainException;
use Illuminate\Support\Facades\DB;

class DecideTimetableApproval
{
    public function __construct(
        private ValidateTimetableVersion $versionValidator,
        private AuditLogger $auditLogger,
    ) {}

    public function handle(
        int $approvalInstanceId,
        ApprovalDecision $decision,
        string $idempotencyKey,
        User $actor,
        ?string $comment = null,
    ): int {
        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '') {
            throw new DomainException('An idempotency key is required for approval decisions.');
        }

        return DB::transaction(function () use ($approvalInstanceId, $decision, $idempotencyKey, $actor, $comment): int {
            $instance = DB::table('approval_instances')
                ->where('id', $approvalInstanceId)
                ->lockForUpdate()
                ->first();

            if ($instance === null) {
                throw new DomainException('The approval instance could not be found.');
            }
            $instance = ApprovalInstanceRow::from($instance);

            $existingAction = DB::table('approval_actions')
                ->where('organization_id', $instance->organizationId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingAction !== null) {
                if ((int) $existingAction->actor_user_id !== $actor->id) {
                    throw new DomainException('The idempotency key has already been used by another actor.');
                }

                return (int) $existingAction->id;
            }

            $organization = Organization::query()
                ->whereKey($instance->organizationId)
                ->firstOrFail();

            if (! $actor->belongsToOrganization($organization)) {
                throw new DomainException('The decision actor is not a member of this organization.');
            }

            if ($instance->status !== ApprovalInstanceStatus::Pending->value) {
                throw new DomainException('The approval instance is no longer active.');
            }

            $version = TimetableVersion::query()
                ->whereKey($instance->timetableVersionId)
                ->lockForUpdate()
                ->firstOrFail();
            $activeStep = DB::table('approval_instance_steps')
                ->where('approval_instance_id', $instance->id)
                ->where('status', ApprovalInstanceStepStatus::Active->value)
                ->lockForUpdate()
                ->first();

            if ($activeStep === null) {
                throw new DomainException('The approval instance has no active step.');
            }
            $activeStep = ApprovalInstanceStepRow::from($activeStep);

            if ($decision !== ApprovalDecision::Cancel) {
                $this->authorizeStep($instance, $activeStep, $organization, $actor);
            } elseif (
                $instance->submittedBy !== $actor->id
                && ! $actor->hasOrganizationPermission($organization, OrganizationPermission::ManageScheduling)
            ) {
                throw new DomainException('Only the submitter or a scheduling manager may cancel an approval.');
            }

            $hasPriorDecision = DB::table('approval_actions')
                ->where('approval_instance_step_id', $activeStep->id)
                ->where('actor_user_id', $actor->id)
                ->exists();

            if ($hasPriorDecision) {
                throw new DomainException('The actor has already decided on this approval step.');
            }

            $signatory = $this->signatorySnapshot($organization, $actor);
            $actionId = DB::table('approval_actions')->insertGetId([
                'organization_id' => $organization->id,
                'approval_instance_step_id' => $activeStep->id,
                'actor_user_id' => $actor->id,
                'decision' => $decision->value,
                'comment' => $comment,
                'idempotency_key' => $idempotencyKey,
                'signatory_name' => $signatory['name'],
                'signatory_position' => $signatory['position'],
                'signatory_academic_unit' => $signatory['academic_unit_name'],
                'signature_disk' => $signatory['signature_disk'],
                'signature_path' => $signatory['signature_path'],
                'signature_checksum' => $signatory['signature_checksum'],
                'acted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $before = [
                'instance_status' => $instance->status,
                'step_status' => $activeStep->status,
                'version_status' => $version->status->value,
            ];

            if ($decision === ApprovalDecision::Approve) {
                $this->advanceAfterApproval($instance, $activeStep, $version, $organization);
            } else {
                $this->terminate($instance, $activeStep, $version, $decision);
            }

            $instanceStatus = DB::table('approval_instances')->where('id', $instance->id)->value('status');
            $stepStatus = DB::table('approval_instance_steps')->where('id', $activeStep->id)->value('status');
            $version->refresh();

            $this->auditLogger->record(
                action: 'approval.decision_recorded',
                organization: $organization,
                actor: $actor,
                subject: $version,
                before: $before,
                after: [
                    'approval_instance_id' => (int) $instance->id,
                    'approval_instance_step_id' => (int) $activeStep->id,
                    'decision' => $decision->value,
                    'instance_status' => $instanceStatus,
                    'step_status' => $stepStatus,
                    'version_status' => $version->status->value,
                ],
            );

            return $actionId;
        }, attempts: 3);
    }

    private function authorizeStep(ApprovalInstanceRow $instance, ApprovalInstanceStepRow $step, Organization $organization, User $actor): void
    {
        if (! $step->allowSelfApproval && $instance->submittedBy === $actor->id) {
            throw new DomainException('The submitter cannot approve this workflow step.');
        }

        $selectorType = $step->approverSelectorType;

        if ($selectorType === 'permission') {
            $permission = OrganizationPermission::tryFrom((string) $step->requiredPermission);

            if ($permission !== null && $actor->hasOrganizationPermission($organization, $permission)) {
                return;
            }
        }

        if ($selectorType === 'role' && $this->hasSnapshotRole($step, $organization, $actor)) {
            return;
        }

        throw new DomainException('The actor is not eligible for the active approval step.');
    }

    private function hasSnapshotRole(ApprovalInstanceStepRow $step, Organization $organization, User $actor): bool
    {
        if ($step->approverRoleCodes === []) {
            return false;
        }

        return DB::table('membership_role_assignments')
            ->join('organization_members', 'organization_members.id', '=', 'membership_role_assignments.membership_id')
            ->join('roles', 'roles.id', '=', 'membership_role_assignments.role_id')
            ->where('membership_role_assignments.organization_id', $organization->id)
            ->where('organization_members.user_id', $actor->id)
            ->where('organization_members.organization_id', $organization->id)
            ->where('roles.organization_id', $organization->id)
            ->whereIn('roles.code', $step->approverRoleCodes)
            ->exists();
    }

    private function advanceAfterApproval(ApprovalInstanceRow $instance, ApprovalInstanceStepRow $activeStep, TimetableVersion $version, Organization $organization): void
    {
        $approvalCount = DB::table('approval_actions')
            ->where('approval_instance_step_id', $activeStep->id)
            ->where('decision', ApprovalDecision::Approve->value)
            ->count();

        if ($approvalCount < $activeStep->minimumApprovals) {
            return;
        }

        $nextStep = DB::table('approval_instance_steps')
            ->where('approval_instance_id', $instance->id)
            ->where('status', ApprovalInstanceStepStatus::Pending->value)
            ->orderBy('sequence')
            ->lockForUpdate()
            ->first();

        if ($nextStep !== null) {
            $nextStep = ApprovalInstanceStepRow::from($nextStep);
            DB::table('approval_instance_steps')->where('id', $activeStep->id)->update([
                'status' => ApprovalInstanceStepStatus::Approved->value,
                'updated_at' => now(),
            ]);
            DB::table('approval_instance_steps')->where('id', $nextStep->id)->update([
                'status' => ApprovalInstanceStepStatus::Active->value,
                'updated_at' => now(),
            ]);

            return;
        }

        $hardIssues = $this->versionValidator->hardIssues($organization, $version);

        if ($hardIssues !== []) {
            throw new ScheduleConflictException($hardIssues);
        }

        DB::table('approval_instance_steps')->where('id', $activeStep->id)->update([
            'status' => ApprovalInstanceStepStatus::Approved->value,
            'updated_at' => now(),
        ]);
        DB::table('approval_instances')->where('id', $instance->id)->update([
            'status' => ApprovalInstanceStatus::Approved->value,
            'completed_at' => now(),
            'updated_at' => now(),
        ]);
        $version->update([
            'status' => TimetableVersionStatus::Approved,
            'approved_at' => now(),
        ]);
    }

    private function terminate(ApprovalInstanceRow $instance, ApprovalInstanceStepRow $activeStep, TimetableVersion $version, ApprovalDecision $decision): void
    {
        if ($decision === ApprovalDecision::Approve) {
            throw new DomainException('Approval decisions cannot terminate as approval.');
        }

        $instanceStatus = match ($decision) {
            ApprovalDecision::Reject => ApprovalInstanceStatus::Rejected,
            ApprovalDecision::RequestChanges => ApprovalInstanceStatus::ChangesRequested,
            ApprovalDecision::Cancel => ApprovalInstanceStatus::Cancelled,
        };
        $stepStatus = match ($decision) {
            ApprovalDecision::Reject => ApprovalInstanceStepStatus::Rejected,
            ApprovalDecision::RequestChanges => ApprovalInstanceStepStatus::ChangesRequested,
            ApprovalDecision::Cancel => ApprovalInstanceStepStatus::Cancelled,
        };

        DB::table('approval_instance_steps')->where('id', $activeStep->id)->update([
            'status' => $stepStatus->value,
            'updated_at' => now(),
        ]);
        DB::table('approval_instances')->where('id', $instance->id)->update([
            'status' => $instanceStatus->value,
            'completed_at' => now(),
            'updated_at' => now(),
        ]);

        if ($decision === ApprovalDecision::RequestChanges || $decision === ApprovalDecision::Cancel) {
            $version->update(['status' => TimetableVersionStatus::ChangesRequested]);
        }
    }

    /**
     * @return array{name: string|null, position: string|null, academic_unit_name: string|null, signature_disk: string|null, signature_path: string|null, signature_checksum: string|null}
     */
    private function signatorySnapshot(Organization $organization, User $actor): array
    {
        $today = now()->toDateString();
        $profile = DB::table('signatory_profiles')
            ->where('organization_id', $organization->id)
            ->where('user_id', $actor->id)
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', $today))
            ->orderByDesc('id')
            ->first();

        return [
            'name' => $profile?->name,
            'position' => $profile?->position,
            'academic_unit_name' => $profile?->academic_unit_name,
            'signature_disk' => $profile?->signature_disk,
            'signature_path' => $profile?->signature_path,
            'signature_checksum' => $profile?->signature_checksum,
        ];
    }
}
