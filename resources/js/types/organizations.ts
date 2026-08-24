export type OrganizationRole = 'owner' | 'admin' | 'member';

export type Organization = {
    id: string;
    name: string;
    slug: string;
    role?: OrganizationRole;
    roleLabel?: string;
    isCurrent?: boolean;
};

export type OrganizationEntitlements = Record<string, boolean | number | null>;

export type OrganizationMember = {
    id: string;
    name: string;
    email: string;
    avatar?: string | null;
    role: OrganizationRole;
    role_label: string;
};

export type OrganizationInvitation = {
    id: string;
    email: string;
    role: OrganizationRole;
    role_label: string;
    created_at: string;
};

export type OrganizationInvitationContext = {
    token: string;
    organizationName: string;
};

export type DashboardInvitation = {
    id: string;
    inviterName: string;
    organization: {
        name: string;
        slug: string;
    };
};

export type OrganizationPermissions = {
    canUpdateOrganization: boolean;
    canDeleteOrganization: boolean;
    canAddMember: boolean;
    canUpdateMember: boolean;
    canRemoveMember: boolean;
    canCreateInvitation: boolean;
    canCancelInvitation: boolean;
    canManageCustomRoles: boolean;
    customRolesEnabled: boolean;
};

export type RoleOption = {
    value: OrganizationRole;
    label: string;
};

export type OrganizationRoleDefinition = {
    code: string;
    name: string;
    is_system: boolean;
    members_count: number;
    permissions: string[];
};

export type OrganizationPermissionOption = {
    code: string;
    name: string;
    module: string;
};
