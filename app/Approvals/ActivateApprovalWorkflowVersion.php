<?php

namespace App\Approvals;

use App\Audit\AuditLogger;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;

class ActivateApprovalWorkflowVersion
{
    public function __construct(
        private TenantContext $tenantContext,
        private ApprovalWorkflowAuthorizer $authorizer,
        private ValidateApprovalWorkflowVersion $validator,
        private AuditLogger $auditLogger,
    ) {}

    public function handle(Organization $organization, User $actor, int $workflowVersionId): void
    {
        $this->tenantContext->run($organization, function () use ($organization, $actor, $workflowVersionId): void {
            $this->authorizer->authorize($organization, $actor);

            DB::transaction(function () use ($organization, $actor, $workflowVersionId): void {
                $version = DB::table('approval_workflow_versions')
                    ->where('organization_id', $organization->id)
                    ->where('id', $workflowVersionId)
                    ->lockForUpdate()
                    ->first();

                if ($version === null) {
                    throw new DomainException('The approval workflow version does not belong to this organization.');
                }

                $workflow = DB::table('approval_workflows')
                    ->where('organization_id', $organization->id)
                    ->where('id', $version->approval_workflow_id)
                    ->lockForUpdate()
                    ->first();

                if ($workflow === null) {
                    throw new DomainException('The approval workflow does not belong to this organization.');
                }

                if ($version->activated_at !== null) {
                    return;
                }

                $steps = $this->persistedSteps($organization, (int) $version->id);
                $this->validator->validate($organization, $steps);

                DB::table('approval_workflow_versions')->where('id', $version->id)->update([
                    'activated_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('approval_workflows')->where('id', $workflow->id)->update([
                    'is_active' => true,
                    'updated_at' => now(),
                ]);

                $this->auditLogger->record(
                    action: 'approval_workflow.version_activated',
                    organization: $organization,
                    actor: $actor,
                    after: [
                        'workflow_id' => $workflow->public_id,
                        'version_id' => $version->id,
                        'version_number' => (int) $version->version_number,
                    ],
                );
            }, attempts: 3);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function persistedSteps(Organization $organization, int $workflowVersionId): array
    {
        $steps = [];

        foreach (DB::table('approval_workflow_steps')
            ->where('organization_id', $organization->id)
            ->where('approval_workflow_version_id', $workflowVersionId)
            ->orderBy('sequence')
            ->get() as $step) {
            $roleCodes = DB::table('approval_step_roles')
                ->join('roles', 'roles.id', '=', 'approval_step_roles.role_id')
                ->where('approval_step_roles.approval_workflow_step_id', $step->id)
                ->where('roles.organization_id', $organization->id)
                ->orderBy('roles.code')
                ->pluck('roles.code')
                ->values()
                ->all();
            $normalizedRoleCodes = [];

            foreach ($roleCodes as $roleCode) {
                if (is_string($roleCode)) {
                    $normalizedRoleCodes[] = $roleCode;
                }
            }

            $steps[] = [
                'sequence' => (int) $step->sequence,
                'label' => (string) $step->label,
                'approver_selector_type' => (string) $step->approver_selector_type,
                'required_permission' => is_string($step->required_permission) ? $step->required_permission : null,
                'role_codes' => $normalizedRoleCodes,
                'minimum_approvals' => (int) $step->minimum_approvals,
                'allow_self_approval' => (bool) $step->allow_self_approval,
                'signatory_slot' => is_string($step->signatory_slot) ? $step->signatory_slot : null,
            ];
        }

        return $steps;
    }
}
