<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import {
    ChevronDown,
    Mail,
    Search,
    Settings2,
    ShieldCheck,
    UserMinus,
    UserPlus,
    Users,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import CancelOrganizationInvitationModal from '@/components/CancelOrganizationInvitationModal.vue';
import DeleteOrganizationModal from '@/components/DeleteOrganizationModal.vue';
import InputError from '@/components/InputError.vue';
import InviteOrganizationMemberModal from '@/components/InviteOrganizationMemberModal.vue';
import OrganizationRoleManager from '@/components/OrganizationRoleManager.vue';
import RemoveOrganizationMemberModal from '@/components/RemoveOrganizationMemberModal.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ValidationSummary from '@/components/ValidationSummary.vue';
import WorkspacePageHeader from '@/components/WorkspacePageHeader.vue';
import WorkspaceState from '@/components/WorkspaceState.vue';
import { useInitials } from '@/composables/useInitials';
import { edit, index, update } from '@/routes/organizations';
import { update as updateMember } from '@/routes/organizations/members';
import type {
    RoleOption,
    Organization,
    OrganizationInvitation,
    OrganizationMember,
    OrganizationPermissionOption,
    OrganizationPermissions,
    OrganizationRoleDefinition,
    ValidationErrors,
} from '@/types';

type Props = {
    organization: Organization;
    members: OrganizationMember[];
    invitations: OrganizationInvitation[];
    permissions: OrganizationPermissions;
    availableRoles: RoleOption[];
    roles: OrganizationRoleDefinition[];
    availablePermissions: OrganizationPermissionOption[];
};

const props = defineProps<Props>();

defineOptions({
    layout: (props: { organization: Organization }) => ({
        breadcrumbs: [
            {
                title: 'Organizations',
                href: index(),
            },
            {
                title: props.organization.name,
                href: edit(props.organization.slug),
            },
        ],
    }),
});

const { getInitials } = useInitials();

const inviteDialogOpen = ref(false);
const deleteDialogOpen = ref(false);
const removeMemberDialogOpen = ref(false);
const memberToRemove = ref<OrganizationMember | null>(null);
const cancelInvitationDialogOpen = ref(false);
const invitationToCancel = ref<OrganizationInvitation | null>(null);
const section = ref<'members' | 'invitations' | 'roles' | 'details'>('members');
const memberSearch = ref('');
const memberRole = ref('');
const invitationSearch = ref('');
const roleErrors = ref<ValidationErrors>({});
const roleChangeMember = ref('');
const updatingMember = ref<string | null>(null);
const sections = computed(() => [
    {
        value: 'members' as const,
        label: 'Members',
        icon: Users,
        count: props.members.length,
    },
    {
        value: 'invitations' as const,
        label: 'Invitations',
        icon: Mail,
        count: props.invitations.length,
    },
    {
        value: 'roles' as const,
        label: 'Roles',
        icon: ShieldCheck,
        count: props.roles.length,
    },
    {
        value: 'details' as const,
        label: 'Details',
        icon: Settings2,
        count: null,
    },
]);
const filteredMembers = computed(() =>
    props.members.filter(
        (member) =>
            (!memberRole.value || member.role === memberRole.value) &&
            `${member.name} ${member.email} ${member.role_label}`
                .toLowerCase()
                .includes(memberSearch.value.trim().toLowerCase()),
    ),
);
const filteredInvitations = computed(() =>
    props.invitations.filter((invitation) =>
        `${invitation.email} ${invitation.role_label}`
            .toLowerCase()
            .includes(invitationSearch.value.trim().toLowerCase()),
    ),
);
const invitationDate = (date: string): string =>
    new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(new Date(date));

function invitationSent(): void {
    section.value = 'invitations';
    invitationSearch.value = '';
}

function clearMemberFilters(): void {
    memberSearch.value = '';
    memberRole.value = '';
}

const updateMemberRole = (member: OrganizationMember, newRole: string) => {
    roleErrors.value = {};
    roleChangeMember.value = member.name;
    router.visit(updateMember([props.organization.slug, member.id]), {
        data: { role: newRole },
        preserveScroll: true,
        preserveState: true,
        onStart: () => (updatingMember.value = member.id),
        onError: (errors) => (roleErrors.value = errors),
        onFinish: () => (updatingMember.value = null),
    });
};

const confirmRemoveMember = (member: OrganizationMember) => {
    memberToRemove.value = member;
    removeMemberDialogOpen.value = true;
};

const confirmCancelInvitation = (invitation: OrganizationInvitation) => {
    invitationToCancel.value = invitation;
    cancelInvitationDialogOpen.value = true;
};
</script>

<template>
    <div
        class="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-6 p-4 sm:p-6"
    >
        <Head :title="`Members & settings - ${organization.name}`" />
        <WorkspacePageHeader
            section="Organization"
            title="Members & settings"
            description="Manage the people and permissions in your organization."
        >
            <template #metadata
                ><span>{{ organization.name }}</span></template
            >
            <template #actions>
                <Button
                    v-if="permissions.canCreateInvitation"
                    data-test="invite-member-button"
                    @click="inviteDialogOpen = true"
                >
                    <UserPlus aria-hidden="true" /> Invite member
                </Button>
            </template>
        </WorkspacePageHeader>

        <nav
            aria-label="Organization sections"
            class="flex flex-wrap gap-x-5 gap-y-1 border-b"
        >
            <button
                v-for="item in sections"
                :key="item.value"
                type="button"
                :aria-current="section === item.value ? 'page' : undefined"
                :aria-controls="`organization-${item.value}`"
                class="flex min-h-11 items-center gap-2 border-b-2 px-1 py-2 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-ring"
                :class="
                    section === item.value
                        ? 'border-schedule text-schedule'
                        : 'border-transparent text-muted-foreground hover:text-foreground'
                "
                @click="section = item.value"
            >
                <component :is="item.icon" class="size-4" aria-hidden="true" />
                {{ item.label }}
                <span v-if="item.count !== null" class="font-mono text-xs">{{
                    item.count
                }}</span>
            </button>
        </nav>

        <section
            id="organization-members"
            v-show="section === 'members'"
            aria-labelledby="members-heading"
            class="space-y-4"
        >
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
            >
                <div>
                    <h2 id="members-heading" class="text-base font-semibold">
                        Member directory
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Find a colleague and review their organization access.
                    </p>
                </div>
                <div class="flex min-w-0 flex-col gap-2 sm:flex-row">
                    <div class="relative min-w-0">
                        <Search
                            class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            v-model="memberSearch"
                            aria-label="Search members"
                            placeholder="Find a name or email..."
                            class="pl-9 sm:w-64"
                            type="search"
                        />
                    </div>
                    <select
                        v-model="memberRole"
                        aria-label="Filter members by role"
                        class="h-9 min-w-0 rounded-md border bg-background px-3 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                    >
                        <option value="">All roles</option>
                        <option
                            v-for="role in roles.filter(
                                (item) => item.is_system,
                            )"
                            :key="role.code"
                            :value="role.code"
                        >
                            {{ role.name }}
                        </option>
                    </select>
                </div>
            </div>
            <ValidationSummary
                :errors="roleErrors"
                :title="`Could not update access for ${roleChangeMember}`"
                :field-labels="{ role: 'Organization role' }"
            />
            <p role="status" class="text-xs text-muted-foreground">
                Showing {{ filteredMembers.length }} of
                {{ members.length }} members
            </p>
            <WorkspaceState
                v-if="filteredMembers.length === 0"
                variant="empty"
                title="No matching members"
                description="Try another name or email, or clear the role filter."
            >
                <template #action
                    ><Button variant="outline" @click="clearMemberFilters"
                        >Clear filters</Button
                    ></template
                >
            </WorkspaceState>
            <ul
                v-else
                class="divide-y overflow-hidden rounded-lg border bg-card"
                aria-label="Organization members"
            >
                <li
                    v-for="member in filteredMembers"
                    :key="member.id"
                    data-test="member-row"
                    class="flex flex-col gap-3 p-4 transition-colors hover:bg-muted/30 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <Avatar class="size-10 shrink-0 rounded-md border">
                            <AvatarImage
                                v-if="member.avatar"
                                :src="member.avatar"
                                :alt="member.name"
                            />
                            <AvatarFallback
                                class="rounded-md bg-muted font-medium"
                                >{{ getInitials(member.name) }}</AvatarFallback
                            >
                        </Avatar>
                        <div class="min-w-0">
                            <p class="font-medium break-words">
                                {{ member.name }}
                            </p>
                            <p class="text-sm break-all text-muted-foreground">
                                {{ member.email }}
                            </p>
                        </div>
                    </div>
                    <div
                        class="flex shrink-0 flex-wrap items-center gap-2 max-sm:pl-13"
                    >
                        <DropdownMenu
                            v-if="
                                member.role !== 'owner' &&
                                permissions.canUpdateMember
                            "
                        >
                            <DropdownMenuTrigger as-child>
                                <Button
                                    data-test="member-role-trigger"
                                    variant="outline"
                                    :aria-label="`Change role for ${member.name}`"
                                    :disabled="updatingMember !== null"
                                >
                                    {{
                                        updatingMember === member.id
                                            ? 'Saving...'
                                            : member.role_label
                                    }}
                                    <ChevronDown
                                        class="size-4 opacity-50"
                                        aria-hidden="true"
                                    />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    v-for="role in availableRoles"
                                    :key="role.value"
                                    data-test="member-role-option"
                                    :disabled="member.role === role.value"
                                    @click="
                                        updateMemberRole(member, role.value)
                                    "
                                >
                                    {{ role.label }}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <Badge v-else variant="secondary">{{
                            member.role_label
                        }}</Badge>
                        <Button
                            v-if="
                                member.role !== 'owner' &&
                                permissions.canRemoveMember
                            "
                            data-test="member-remove-button"
                            variant="ghost"
                            :aria-label="`Remove ${member.name}`"
                            @click="confirmRemoveMember(member)"
                        >
                            <UserMinus class="size-4" aria-hidden="true" /><span
                                class="sm:sr-only"
                                >Remove</span
                            >
                        </Button>
                    </div>
                </li>
            </ul>
        </section>

        <section
            id="organization-invitations"
            v-show="section === 'invitations'"
            aria-labelledby="invitations-heading"
            class="space-y-4"
        >
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
            >
                <div>
                    <h2
                        id="invitations-heading"
                        class="text-base font-semibold"
                    >
                        Pending invitations
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        People who have been invited and have not joined yet.
                    </p>
                </div>
                <div v-if="invitations.length" class="relative">
                    <Search
                        class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        v-model="invitationSearch"
                        aria-label="Search invitations"
                        placeholder="Find an email or role..."
                        type="search"
                        class="pl-9 sm:w-72"
                    />
                </div>
            </div>
            <WorkspaceState
                v-if="invitations.length === 0"
                variant="empty"
                title="No pending invitations"
                :description="
                    permissions.canCreateInvitation
                        ? 'Invite a colleague to give them access to this organization.'
                        : 'Invitations will appear here when an administrator sends them.'
                "
            />
            <WorkspaceState
                v-else-if="filteredInvitations.length === 0"
                variant="empty"
                title="No matching invitations"
                description="Try another email or role to find the invitation."
            >
                <template #action
                    ><Button variant="outline" @click="invitationSearch = ''"
                        >Clear search</Button
                    ></template
                >
            </WorkspaceState>
            <ul
                v-else
                class="divide-y overflow-hidden rounded-lg border bg-card"
                aria-label="Pending invitations"
            >
                <li
                    v-for="invitation in filteredInvitations"
                    :key="invitation.id"
                    data-test="invitation-row"
                    class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <span
                            class="grid size-10 shrink-0 place-items-center rounded-md border bg-muted text-muted-foreground"
                            ><Mail class="size-4" aria-hidden="true"
                        /></span>
                        <div class="min-w-0">
                            <p class="font-medium break-all">
                                {{ invitation.email }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ invitation.role_label }} · Sent
                                {{ invitationDate(invitation.created_at) }}
                            </p>
                        </div>
                    </div>
                    <Button
                        v-if="permissions.canCancelInvitation"
                        data-test="invitation-cancel-button"
                        variant="ghost"
                        class="shrink-0 self-start sm:self-auto"
                        :aria-label="`Cancel invitation for ${invitation.email}`"
                        @click="confirmCancelInvitation(invitation)"
                    >
                        <X class="size-4" aria-hidden="true" /> Cancel
                        invitation
                    </Button>
                </li>
            </ul>
        </section>

        <div id="organization-roles" v-show="section === 'roles'">
            <OrganizationRoleManager
                :organization="organization"
                :roles="roles"
                :available-permissions="availablePermissions"
                :can-manage-roles="permissions.canManageCustomRoles"
                :custom-roles-enabled="permissions.customRolesEnabled"
            />
        </div>

        <section
            id="organization-details"
            v-show="section === 'details'"
            aria-labelledby="details-heading"
            class="space-y-6"
        >
            <div>
                <h2 id="details-heading" class="text-base font-semibold">
                    Organization details
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    The name members see throughout the workspace.
                </p>
            </div>
            <Form
                v-if="permissions.canUpdateOrganization"
                v-bind="update.form(organization.slug)"
                class="max-w-2xl space-y-5 rounded-lg border bg-card p-5"
                v-slot="{ errors, processing }"
            >
                <ValidationSummary
                    :errors="errors"
                    title="Check the organization details"
                    :field-labels="{ name: 'Organization name' }"
                    :field-ids="{ name: 'organization-name' }"
                />
                <div class="grid gap-2">
                    <Label for="organization-name">Organization name</Label>
                    <Input
                        id="organization-name"
                        name="name"
                        data-test="organization-name-input"
                        :default-value="organization.name"
                        :aria-invalid="Boolean(errors.name)"
                        aria-describedby="organization-name-error"
                        required
                    />
                    <InputError
                        id="organization-name-error"
                        :message="errors.name"
                    />
                </div>
                <Button
                    type="submit"
                    data-test="organization-save-button"
                    :disabled="processing"
                    >{{ processing ? 'Saving...' : 'Save changes' }}</Button
                >
            </Form>
            <dl v-else class="max-w-2xl rounded-lg border bg-card p-5">
                <dt class="text-sm text-muted-foreground">Organization name</dt>
                <dd class="mt-1 font-medium">{{ organization.name }}</dd>
            </dl>
            <div
                v-if="permissions.canDeleteOrganization"
                class="flex max-w-2xl flex-col gap-4 rounded-lg border border-conflict/30 p-5 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <h3 class="text-sm font-semibold">Delete organization</h3>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Permanently remove {{ organization.name }}. This cannot
                        be undone.
                    </p>
                </div>
                <Button
                    data-test="delete-organization-button"
                    variant="destructive"
                    class="shrink-0 self-start sm:self-auto"
                    @click="deleteDialogOpen = true"
                    >Delete organization</Button
                >
            </div>
        </section>

        <InviteOrganizationMemberModal
            v-if="permissions.canCreateInvitation"
            :organization="organization"
            :available-roles="availableRoles"
            v-model:open="inviteDialogOpen"
            @sent="invitationSent"
        />
        <RemoveOrganizationMemberModal
            :organization="organization"
            :member="memberToRemove"
            v-model:open="removeMemberDialogOpen"
        />
        <CancelOrganizationInvitationModal
            :organization="organization"
            :invitation="invitationToCancel"
            v-model:open="cancelInvitationDialogOpen"
        />
        <DeleteOrganizationModal
            v-if="permissions.canDeleteOrganization"
            :organization="organization"
            v-model:open="deleteDialogOpen"
        />
    </div>
</template>
