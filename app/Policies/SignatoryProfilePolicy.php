<?php

namespace App\Policies;

use App\Approvals\ApprovalWorkflowAuthorizer;
use App\Enums\OrganizationPermission;
use App\Models\Organization;
use App\Models\SignatoryProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class SignatoryProfilePolicy extends OrganizationOwnedPolicy
{
    public function __construct(private ApprovalWorkflowAuthorizer $authorizer) {}

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->canManageSignatories($user, $organization);
    }

    public function view(User $user, Model $model): bool
    {
        return $model instanceof SignatoryProfile
            && $this->canManageSignatory($user, $model);
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->canManageSignatories($user, $organization);
    }

    public function update(User $user, Model $model): bool
    {
        return $model instanceof SignatoryProfile
            && $this->canManageSignatory($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $model instanceof SignatoryProfile
            && $this->canManageSignatory($user, $model);
    }

    protected function managementPermission(): OrganizationPermission
    {
        return OrganizationPermission::ManageScheduling;
    }

    private function canManageSignatory(User $user, SignatoryProfile $profile): bool
    {
        $organization = $this->organizationFor($profile);

        return $organization instanceof Organization
            && $this->canManageSignatories($user, $organization);
    }

    private function canManageSignatories(User $user, Organization $organization): bool
    {
        return $this->authorizer->allows($organization, $user);
    }
}
