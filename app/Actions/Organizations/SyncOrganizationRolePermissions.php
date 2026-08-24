<?php

namespace App\Actions\Organizations;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Validation\ValidationException;

class SyncOrganizationRolePermissions
{
    /**
     * Synchronize a role's canonical permissions with tenant pivot values.
     *
     * @param  array<int, string>  $permissionCodes
     */
    public function handle(Role $role, array $permissionCodes): Role
    {
        $permissionCodes = array_values(array_unique($permissionCodes));
        $permissions = Permission::query()
            ->whereIn('code', $permissionCodes)
            ->get();

        if ($permissions->count() !== count($permissionCodes)) {
            throw ValidationException::withMessages([
                'permissions' => __('Select only supported organization permissions.'),
            ]);
        }

        $role->permissions()->syncWithPivotValues(
            $permissions->modelKeys(),
            ['organization_id' => $role->organization_id],
        );

        return $role->load('permissions');
    }
}
