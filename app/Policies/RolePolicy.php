<?php

namespace App\Policies;

use App\Enums\CapabilityKey;
use App\Enums\OrganizationPermission;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Subscriptions\CapabilityGuard;
use Illuminate\Database\Eloquent\Model;

class RolePolicy extends OrganizationOwnedPolicy
{
    public function __construct(
        private CapabilityGuard $capabilities,
    ) {}

    /**
     * Determine whether the user can create a custom role.
     */
    public function create(User $user, Organization $organization): bool
    {
        return $this->canManageCustomRoles($user, $organization);
    }

    /**
     * Determine whether the user can update a custom role.
     */
    public function update(User $user, Model $model): bool
    {
        return $model instanceof Role
            && $this->canManageCustomRole($user, $model);
    }

    /**
     * Determine whether the user can delete a custom role.
     */
    public function delete(User $user, Model $model): bool
    {
        return $model instanceof Role
            && $this->canManageCustomRole($user, $model);
    }

    /**
     * Get the permission required for role administration.
     */
    protected function managementPermission(): OrganizationPermission
    {
        return OrganizationPermission::UpdateMember;
    }

    private function canManageCustomRoles(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationPermission($organization, $this->managementPermission())
            && $this->capabilities->allows($organization, CapabilityKey::CustomRoles);
    }

    private function canManageCustomRole(User $user, Role $role): bool
    {
        $organization = $this->organizationFor($role);

        return $organization instanceof Organization
            && ! $role->is_system
            && $this->canManageCustomRoles($user, $organization);
    }
}
