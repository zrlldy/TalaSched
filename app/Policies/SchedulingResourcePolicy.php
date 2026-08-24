<?php

namespace App\Policies;

use App\Enums\OrganizationPermission;

class SchedulingResourcePolicy extends OrganizationOwnedPolicy
{
    protected function managementPermission(): OrganizationPermission
    {
        return OrganizationPermission::ManageResources;
    }
}
