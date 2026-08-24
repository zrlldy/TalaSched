<?php

namespace App\Policies;

use App\Enums\OrganizationPermission;

class SubjectOfferingPolicy extends OrganizationOwnedPolicy
{
    protected function managementPermission(): OrganizationPermission
    {
        return OrganizationPermission::ManageCatalog;
    }
}
