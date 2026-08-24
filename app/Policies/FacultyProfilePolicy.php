<?php

namespace App\Policies;

use App\Enums\OrganizationPermission;

class FacultyProfilePolicy extends OrganizationOwnedPolicy
{
    protected function managementPermission(): OrganizationPermission
    {
        return OrganizationPermission::ManageResources;
    }
}
