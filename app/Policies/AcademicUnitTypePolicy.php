<?php

namespace App\Policies;

use App\Enums\OrganizationPermission;

class AcademicUnitTypePolicy extends OrganizationOwnedPolicy
{
    protected function managementPermission(): OrganizationPermission
    {
        return OrganizationPermission::ManageAcademic;
    }
}
