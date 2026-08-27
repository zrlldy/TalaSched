<?php

namespace App\Approvals;

use App\Audit\AuditLogger;
use App\Enums\ApprovalInstanceStatus;
use App\Enums\TimetableVersionStatus;
use App\Models\Organization;
use App\Models\TimetableVersion;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SubmitTimetableForApproval
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function handle(TimetableVersion $version, int $workflowVersionId, User $actor): int
    {
        Gate::forUser($actor)->authorize('submitForApproval', $version);

        return DB::transaction(function () use ($version, $workflowVersionId, $actor): int {
            $version = TimetableVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();

            if (! $version->status->isEditable()) {
                throw new DomainException('Only an editable timetable version may be submitted.');
            }

            $before = [
                'id' => $version->public_id,
                'status' => $version->status->value,
            ];

            $workflowVersion = DB::table('approval_workflow_versions')
                ->where('id', $workflowVersionId)
                ->where('organization_id', $version->organization_id)
                ->first();

            if ($workflowVersion === null) {
                throw new DomainException('The approval workflow does not belong to this organization.');
            }

            if ($workflowVersion->activated_at === null) {
                throw new DomainException('Only an activated approval workflow version may be submitted.');
            }

            $workflow = DB::table('approval_workflows')
                ->where('organization_id', $version->organization_id)
                ->where('id', $workflowVersion->approval_workflow_id)
                ->first();

            if ($workflow === null || ! $workflow->is_active) {
                throw new DomainException('The approval workflow is retired.');
            }

            $hasActiveInstance = DB::table('approval_instances')
                ->where('organization_id', $version->organization_id)
                ->where('timetable_version_id', $version->id)
                ->where('status', ApprovalInstanceStatus::Pending->value)
                ->exists();

            if ($hasActiveInstance) {
                throw new DomainException('The timetable version already has an active approval instance.');
            }

            $steps = DB::table('approval_workflow_steps')
                ->where('approval_workflow_version_id', $workflowVersionId)
                ->orderBy('sequence')
                ->get();

            if ($steps->isEmpty()) {
                throw new DomainException('The approval workflow must contain at least one step.');
            }

            $instanceId = DB::table('approval_instances')->insertGetId([
                'organization_id' => $version->organization_id,
                'timetable_version_id' => $version->id,
                'approval_workflow_version_id' => $workflowVersionId,
                'status' => ApprovalInstanceStatus::Pending->value,
                'submitted_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($steps as $step) {
                $roleCodes = $step->approver_selector_type === 'role'
                    ? DB::table('approval_step_roles')
                        ->join('roles', 'roles.id', '=', 'approval_step_roles.role_id')
                        ->where('approval_step_roles.approval_workflow_step_id', $step->id)
                        ->where('roles.organization_id', $version->organization_id)
                        ->orderBy('roles.code')
                        ->pluck('roles.code')
                        ->values()
                        ->all()
                    : null;

                DB::table('approval_instance_steps')->insert([
                    'organization_id' => $version->organization_id,
                    'approval_instance_id' => $instanceId,
                    'sequence' => $step->sequence,
                    'label' => $step->label,
                    'academic_unit_id' => $step->academic_unit_id,
                    'approver_selector_type' => $step->approver_selector_type,
                    'required_permission' => $step->required_permission,
                    'approver_role_codes' => $roleCodes === null ? null : json_encode($roleCodes, JSON_THROW_ON_ERROR),
                    'minimum_approvals' => $step->minimum_approvals,
                    'allow_self_approval' => $step->allow_self_approval,
                    'status' => $step->sequence === $steps->first()->sequence ? 'active' : 'pending',
                    'signatory_slot' => $step->signatory_slot,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $version->update([
                'status' => TimetableVersionStatus::InReview,
                'submitted_at' => now(),
            ]);

            $this->auditLogger->record(
                action: 'timetable_version.submitted',
                organization: Organization::query()->findOrFail($version->organization_id),
                actor: $actor,
                subject: $version,
                before: $before,
                after: [
                    'id' => $version->public_id,
                    'status' => $version->status->value,
                    'approval_instance_id' => $instanceId,
                ],
            );

            return $instanceId;
        }, attempts: 3);
    }
}
