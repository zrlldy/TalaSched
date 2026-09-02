<?php

namespace App\Actions\Organizations;

use App\Audit\AuditLogger;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteOrganizationRole
{
    public function __construct(
        private AuditLogger $auditLogger,
        private TenantContext $tenantContext,
    ) {}

    /**
     * Delete an unassigned custom role.
     */
    public function handle(Organization $organization, Role $role, ?User $actor = null): void
    {
        $this->tenantContext->run($organization, function () use ($actor, $organization, $role): void {
            DB::transaction(function () use ($actor, $organization, $role): void {
                $lockedRole = Role::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();

                if ($lockedRole->is_system) {
                    throw ValidationException::withMessages([
                        'role' => __('System roles cannot be deleted.'),
                    ]);
                }

                if ($lockedRole->membershipAssignments()->exists()) {
                    throw ValidationException::withMessages([
                        'role' => __('Remove this role from all members before deleting it.'),
                    ]);
                }

                $before = $this->snapshot($lockedRole->load('permissions'));
                $lockedRole->delete();

                $this->auditLogger->record(
                    action: 'organization.role_deleted',
                    organization: $organization,
                    actor: $actor,
                    subject: $lockedRole,
                    before: $before,
                    after: ['status' => 'deleted'],
                );
            });
        });
    }

    /**
     * @return array{name: string, code: string, permission_codes: array<int, string>}
     */
    private function snapshot(Role $role): array
    {
        return [
            'name' => $role->name,
            'code' => $role->code,
            'permission_codes' => $role->permissions
                ->pluck('code')
                ->sort()
                ->values()
                ->all(),
        ];
    }
}
