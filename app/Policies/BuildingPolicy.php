<?php

namespace App\Policies;

use App\Enums\OrganizationPermission;

class BuildingPolicy extends OrganizationOwnedPolicy
{
    protected function managementPermission(): OrganizationPermission
    {
        return OrganizationPermission::ManageResources;
    }
}
