<?php

namespace App\Approvals;

use App\Audit\AuditLogger;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateApprovalWorkflowVersion
{
    public function __construct(
        private TenantContext $tenantContext,
        private ApprovalWorkflowAuthorizer $authorizer,
        private ValidateApprovalWorkflowVersion $validator,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    public function handle(Organization $organization, User $actor, string $name, array $steps): int
    {
        return $this->tenantContext->run($organization, function () use ($organization, $actor, $name, $steps): int {
            $this->authorizer->authorize($organization, $actor);

            return DB::transaction(function () use ($organization, $actor, $name, $steps): int {
                $name = trim($name);

                if ($name === '') {
                    throw new InvalidArgumentException('Approval workflow names are required.');
                }

                $normalizedSteps = $this->validator->validate($organization, $steps);
                $workflow = DB::table('approval_workflows')
                    ->where('organization_id', $organization->id)
                    ->where('name', $name)
                    ->lockForUpdate()
                    ->first();

                if ($workflow === null) {
                    $workflowId = DB::table('approval_workflows')->insertGetId([
                        'organization_id' => $organization->id,
                        'public_id' => (string) Str::uuid(),
                        'name' => $name,
                        'is_active' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $workflow = DB::table('approval_workflows')->where('id', $workflowId)->firstOrFail();
                }

                $versionNumber = (int) DB::table('approval_workflow_versions')
                    ->where('approval_workflow_id', $workflow->id)
                    ->max('version_number') + 1;
                $versionId = DB::table('approval_workflow_versions')->insertGetId([
                    'organization_id' => $organization->id,
                    'approval_workflow_id' => $workflow->id,
                    'version_number' => $versionNumber,
                    'activated_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($normalizedSteps as $step) {
                    $stepId = DB::table('approval_workflow_steps')->insertGetId([
                        'organization_id' => $organization->id,
                        'approval_workflow_version_id' => $versionId,
                        'sequence' => $step['sequence'],
                        'label' => $step['label'],
                        'approver_selector_type' => $step['approver_selector_type'],
                        'required_permission' => $step['required_permission'],
                        'minimum_approvals' => $step['minimum_approvals'],
                        'allow_self_approval' => $step['allow_self_approval'],
                        'signatory_slot' => $step['signatory_slot'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if ($step['role_codes'] !== []) {
                        $roleIds = DB::table('roles')
                            ->where('organization_id', $organization->id)
                            ->whereIn('code', $step['role_codes'])
                            ->pluck('id')
                            ->map(fn (mixed $id): array => [
                                'organization_id' => $organization->id,
                                'approval_workflow_step_id' => $stepId,
                                'role_id' => (int) $id,
                            ])
                            ->all();
                        DB::table('approval_step_roles')->insert($roleIds);
                    }
                }

                $this->auditLogger->record(
                    action: 'approval_workflow.version_created',
                    organization: $organization,
                    actor: $actor,
                    after: [
                        'workflow_id' => $workflow->public_id,
                        'version_id' => $versionId,
                        'version_number' => $versionNumber,
                        'step_count' => count($normalizedSteps),
                    ],
                );

                return $versionId;
            }, attempts: 3);
        });
    }
}
