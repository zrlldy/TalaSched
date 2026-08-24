<?php

namespace App\Policies;

use App\Enums\OrganizationPermission;

class TimetablePolicy extends OrganizationOwnedPolicy
{
    protected function managementPermission(): OrganizationPermission
    {
        return OrganizationPermission::ManageScheduling;
    }
}
