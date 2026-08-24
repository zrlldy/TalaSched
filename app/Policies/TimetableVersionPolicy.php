<?php

namespace App\Policies;

use App\Enums\CapabilityKey;
use App\Enums\OrganizationPermission;
use App\Models\Organization;
use App\Models\TimetableVersion;
use App\Models\User;
use App\Subscriptions\CapabilityGuard;

class TimetableVersionPolicy extends OrganizationOwnedPolicy
{
    public function __construct(private CapabilityGuard $capabilities) {}

    protected function managementPermission(): OrganizationPermission
    {
        return OrganizationPermission::ManageScheduling;
    }

    public function clone(User $user, TimetableVersion $version): bool
    {
        return $this->canUseVersioning($user, $version);
    }

    public function submitForApproval(User $user, TimetableVersion $version): bool
    {
        $organization = $this->organizationFor($version);

        return $this->canManage($user, $version)
            && $organization instanceof Organization
            && $this->capabilities->allows($organization, CapabilityKey::ApprovalWorkflows);
    }

    public function publish(User $user, TimetableVersion $version): bool
    {
        return $this->canUseVersioning($user, $version);
    }

    public function rollback(User $user, TimetableVersion $version): bool
    {
        return $this->canUseVersioning($user, $version);
    }

    private function canUseVersioning(User $user, TimetableVersion $version): bool
    {
        $organization = $this->organizationFor($version);

        return $this->canManage($user, $version)
            && $organization instanceof Organization
            && $this->capabilities->allows($organization, CapabilityKey::TimetableVersioning);
    }
}
