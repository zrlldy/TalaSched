<?php

namespace App\Policies;

use App\Enums\OrganizationPermission;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;

class OrganizationInvitationPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->belongsToOrganization($organization);
    }

    public function view(User $user, OrganizationInvitation $invitation): bool
    {
        $organization = $this->organization($invitation);

        return $organization instanceof Organization
            && $user->belongsToOrganization($organization);
    }

    public function create(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationPermission($organization, OrganizationPermission::CreateInvitation);
    }

    public function delete(User $user, OrganizationInvitation $invitation): bool
    {
        $organization = $this->organization($invitation);

        return $organization instanceof Organization
            && $user->hasOrganizationPermission($organization, OrganizationPermission::CancelInvitation);
    }

    private function organization(OrganizationInvitation $invitation): ?Organization
    {
        return Organization::query()->find($invitation->organization_id);
    }
}
