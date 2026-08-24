<?php

namespace App\Policies;

use App\Enums\OrganizationPermission;

class AcademicCalendarPolicy extends OrganizationOwnedPolicy
{
    protected function managementPermission(): OrganizationPermission
    {
        return OrganizationPermission::ManageAcademic;
    }
}
