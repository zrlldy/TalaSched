<?php

namespace App\Policies;

use App\Enums\OrganizationPermission;

class AcademicYearPolicy extends OrganizationOwnedPolicy
{
    protected function managementPermission(): OrganizationPermission
    {
        return OrganizationPermission::ManageAcademic;
    }
}
