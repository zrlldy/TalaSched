<?php

namespace App\Policies;

use App\Enums\OrganizationPermission;

class RoomTypePolicy extends OrganizationOwnedPolicy
{
    protected function managementPermission(): OrganizationPermission
    {
        return OrganizationPermission::ManageResources;
    }
}
