<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\Role;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateOrganizationRole
{
    public function __construct(
        private TenantContext $tenantContext,
        private SyncOrganizationRolePermissions $syncPermissions,
    ) {}

    /**
     * Update a custom role's name and selected permissions.
     *
     * @param  array<int, string>  $permissionCodes
     */
    public function handle(Organization $organization, Role $role, string $name, array $permissionCodes): Role
    {
        return $this->tenantContext->run($organization, function () use ($role, $name, $permissionCodes): Role {
            return DB::transaction(function () use ($role, $name, $permissionCodes): Role {
                $lockedRole = Role::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();

                if ($lockedRole->is_system) {
                    throw ValidationException::withMessages([
                        'role' => __('System roles cannot be changed.'),
                    ]);
                }

                $lockedRole->update(['name' => $name]);

                return $this->syncPermissions->handle($lockedRole, $permissionCodes);
            });
        });
    }
}
