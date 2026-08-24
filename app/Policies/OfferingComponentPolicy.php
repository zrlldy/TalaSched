<?php

namespace App\Policies;

use App\Enums\OrganizationPermission;

class OfferingComponentPolicy extends OrganizationOwnedPolicy
{
    protected function managementPermission(): OrganizationPermission
    {
        return OrganizationPermission::ManageCatalog;
    }
}
