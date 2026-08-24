<?php

namespace App\Policies;

use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;

class MembershipPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->belongsToOrganization($organization);
    }

    public function view(User $user, Membership $membership): bool
    {
        $organization = $this->organization($membership);

        return $organization instanceof Organization
            && $user->belongsToOrganization($organization);
    }

    public function create(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationPermission($organization, OrganizationPermission::AddMember);
    }

    public function update(User $user, Membership $membership, ?OrganizationRole $newRole = null): bool
    {
        $organization = $this->organization($membership);

        return $organization instanceof Organization
            && $membership->user_id !== $organization->owner_user_id
            && $user->hasOrganizationPermission($organization, OrganizationPermission::UpdateMember)
            && ($newRole === null || $this->canDelegateRole($user, $organization, $newRole));
    }

    public function delete(User $user, Membership $membership): bool
    {
        $organization = $this->organization($membership);

        return $organization instanceof Organization
            && $membership->user_id !== $organization->owner_user_id
            && $user->hasOrganizationPermission($organization, OrganizationPermission::RemoveMember);
    }

    private function organization(Membership $membership): ?Organization
    {
        return Organization::query()->find($membership->organization_id);
    }

    private function canDelegateRole(User $user, Organization $organization, OrganizationRole $role): bool
    {
        if ($role === OrganizationRole::Owner) {
            return false;
        }

        foreach ($role->permissions() as $permission) {
            if (! $user->hasOrganizationPermission($organization, $permission)) {
                return false;
            }
        }

        return true;
    }
}
