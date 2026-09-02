<?php

namespace App\Actions\Organizations;

use App\Audit\AuditLogger;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateOrganizationRole
{
    public function __construct(
        private AuditLogger $auditLogger,
        private TenantContext $tenantContext,
        private SyncOrganizationRolePermissions $syncPermissions,
    ) {}

    /**
     * Update a custom role's name and selected permissions.
     *
     * @param  array<int, string>  $permissionCodes
     */
    public function handle(Organization $organization, Role $role, string $name, array $permissionCodes, ?User $actor = null): Role
    {
        return $this->tenantContext->run($organization, function () use ($actor, $organization, $role, $name, $permissionCodes): Role {
            return DB::transaction(function () use ($actor, $organization, $role, $name, $permissionCodes): Role {
                $lockedRole = Role::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();

                if ($lockedRole->is_system) {
                    throw ValidationException::withMessages([
                        'role' => __('System roles cannot be changed.'),
                    ]);
                }

                $before = $this->snapshot($lockedRole->load('permissions'));
                $lockedRole->update(['name' => $name]);

                $updatedRole = $this->syncPermissions->handle($lockedRole, $permissionCodes);

                $this->auditLogger->record(
                    action: 'organization.role_updated',
                    organization: $organization,
                    actor: $actor,
                    subject: $updatedRole,
                    before: $before,
                    after: $this->snapshot($updatedRole),
                );

                return $updatedRole;
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
