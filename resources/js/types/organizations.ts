export type OrganizationRole = 'owner' | 'admin' | 'member';

export type Organization = {
    id: string;
    name: string;
    slug: string;
    role?: OrganizationRole;
    roleLabel?: string;
    isCurrent?: boolean;
};

export type OrganizationMember = {
    id: string;
    name: string;
    email: string;
    avatar?: string | null;
    role: OrganizationRole;
    role_label: string;
};

export type OrganizationInvitation = {
    code: string;
    email: string;
    role: OrganizationRole;
    role_label: string;
    created_at: string;
};

export type OrganizationInvitationContext = {
    code: string;
    organizationName: string;
};

export type DashboardInvitation = {
    code: string;
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
};

export type RoleOption = {
    value: OrganizationRole;
    label: string;
};
