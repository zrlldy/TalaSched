<?php

namespace App\Policies;

use App\Enums\CapabilityKey;
use App\Enums\OrganizationPermission;
use App\Models\Organization;
use App\Models\User;
use App\Subscriptions\CapabilityGuard;
use Illuminate\Database\Eloquent\Model;

class ScheduleEntryPolicy extends OrganizationOwnedPolicy
{
    public function __construct(private CapabilityGuard $capabilities) {}

    public function create(User $user, Organization $organization): bool
    {
        return parent::create($user, $organization)
            && $this->hasManualSchedulingEntitlement($organization);
    }

    protected function managementPermission(): OrganizationPermission
    {
        return OrganizationPermission::ManageScheduling;
    }

    protected function canManage(User $user, Model $model): bool
    {
        $organization = $this->organizationFor($model);

        return parent::canManage($user, $model)
            && $organization instanceof Organization
            && $this->hasManualSchedulingEntitlement($organization);
    }

    private function hasManualSchedulingEntitlement(Organization $organization): bool
    {
        return $this->capabilities->allows($organization, CapabilityKey::ManualScheduling);
    }
}
