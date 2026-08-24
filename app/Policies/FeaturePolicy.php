<?php

namespace App\Policies;

use App\Enums\OrganizationPermission;

class FeaturePolicy extends OrganizationOwnedPolicy
{
    protected function managementPermission(): OrganizationPermission
    {
        return OrganizationPermission::ManageResources;
    }
}
