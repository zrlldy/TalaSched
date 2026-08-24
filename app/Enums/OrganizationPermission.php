<?php

namespace App\Enums;

enum OrganizationPermission: string
{
    case UpdateOrganization = 'organization:update';
    case DeleteOrganization = 'organization:delete';

    case AddMember = 'member:add';
    case UpdateMember = 'member:update';
    case RemoveMember = 'member:remove';

    case CreateInvitation = 'invitation:create';
    case CancelInvitation = 'invitation:cancel';

    case ManageAcademic = 'academic:manage';
    case ManageResources = 'resources:manage';
    case ManageCatalog = 'catalog:manage';
    case ManageScheduling = 'scheduling:manage';

    /**
     * Get the stable display name for the permission.
     */
    public function label(): string
    {
        return match ($this) {
            self::UpdateOrganization => 'Update organization',
            self::DeleteOrganization => 'Delete organization',
            self::AddMember => 'Add member',
            self::UpdateMember => 'Update member',
            self::RemoveMember => 'Remove member',
            self::CreateInvitation => 'Create invitation',
            self::CancelInvitation => 'Cancel invitation',
            self::ManageAcademic => 'Manage academic structure',
            self::ManageResources => 'Manage scheduling resources',
            self::ManageCatalog => 'Manage catalog and offerings',
            self::ManageScheduling => 'Manage scheduling',
        };
    }

    /**
     * Get the module that owns the permission.
     */
    public function module(): string
    {
        return explode(':', $this->value, 2)[0];
    }
}
