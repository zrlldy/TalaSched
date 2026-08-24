<?php

namespace App\Approvals;

use App\Audit\AuditLogger;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;

class RetireApprovalWorkflow
{
    public function __construct(
        private TenantContext $tenantContext,
        private ApprovalWorkflowAuthorizer $authorizer,
        private AuditLogger $auditLogger,
    ) {}

    public function handle(Organization $organization, User $actor, int $workflowId): void
    {
        $this->tenantContext->run($organization, function () use ($organization, $actor, $workflowId): void {
            $this->authorizer->authorize($organization, $actor);

            DB::transaction(function () use ($organization, $actor, $workflowId): void {
                $workflow = DB::table('approval_workflows')
                    ->where('organization_id', $organization->id)
                    ->where('id', $workflowId)
                    ->lockForUpdate()
                    ->first();

                if ($workflow === null) {
                    throw new DomainException('The approval workflow does not belong to this organization.');
                }

                if (! $workflow->is_active) {
                    return;
                }

                DB::table('approval_workflows')->where('id', $workflow->id)->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);

                $this->auditLogger->record(
                    action: 'approval_workflow.retired',
                    organization: $organization,
                    actor: $actor,
                    after: [
                        'workflow_id' => $workflow->public_id,
                        'is_active' => false,
                    ],
                );
            }, attempts: 3);
        });
    }
}
