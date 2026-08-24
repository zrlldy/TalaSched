<?php

namespace App\Policies;

use App\Enums\OrganizationPermission;

class AcademicUnitPolicy extends OrganizationOwnedPolicy
{
    protected function managementPermission(): OrganizationPermission
    {
        return OrganizationPermission::ManageAcademic;
    }
}
