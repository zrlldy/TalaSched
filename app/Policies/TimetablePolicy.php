<?php

namespace App\Policies;

use App\Enums\CapabilityKey;
use App\Enums\OrganizationPermission;
use App\Models\Organization;
use App\Models\User;
use App\Subscriptions\CapabilityGuard;

class TimetablePolicy extends OrganizationOwnedPolicy
{
    public function __construct(private CapabilityGuard $capabilities) {}

    public function create(User $user, Organization $organization): bool
    {
        return parent::create($user, $organization)
            && $this->capabilities->allows($organization, CapabilityKey::ManualScheduling);
    }

    protected function managementPermission(): OrganizationPermission
    {
        return OrganizationPermission::ManageScheduling;
    }
}
