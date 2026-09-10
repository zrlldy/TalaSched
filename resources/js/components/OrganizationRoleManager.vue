<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Pencil, Plus, Search, ShieldCheck, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ValidationSummary from '@/components/ValidationSummary.vue';
import WorkspaceState from '@/components/WorkspaceState.vue';
import { destroy, store, update } from '@/routes/organizations/roles';
import type {
    Organization,
    OrganizationPermissionOption,
    OrganizationRoleDefinition,
} from '@/types';

type Props = {
    organization: Organization;
    roles: OrganizationRoleDefinition[];
    availablePermissions: OrganizationPermissionOption[];
    canManageRoles: boolean;
    customRolesEnabled: boolean;
};

const props = defineProps<Props>();

const editorOpen = ref(false);
const editingRole = ref<OrganizationRoleDefinition | null>(null);
const formKey = ref(0);
const search = ref('');
const roleToDelete = ref<OrganizationRoleDefinition | null>(null);
const deleteDialogOpen = ref(false);
const filteredRoles = computed(() =>
    props.roles.filter((role) =>
        role.name.toLowerCase().includes(search.value.trim().toLowerCase()),
    ),
);

const editorTitle = computed(() =>
    editingRole.value ? 'Edit custom role' : 'Create custom role',
);

const editorDescription = computed(() =>
    editingRole.value
        ? 'Adjust the role name and the permissions it grants.'
        : 'Create a reusable permission set for members of this organization.',
);

const formAction = computed(() =>
    editingRole.value
        ? update.url([props.organization.slug, editingRole.value.code])
        : store.url(props.organization.slug),
);

const formMethod = computed<'post' | 'patch'>(() =>
    editingRole.value ? 'patch' : 'post',
);

function openCreate(): void {
    editingRole.value = null;
    formKey.value++;
    editorOpen.value = true;
}

function openEdit(role: OrganizationRoleDefinition): void {
    editingRole.value = role;
    formKey.value++;
    editorOpen.value = true;
}

function closeEditor(): void {
    search.value = '';
    editorOpen.value = false;
    editingRole.value = null;
    formKey.value++;
}

function deleteRole(role: OrganizationRoleDefinition): void {
    roleToDelete.value = role;
    deleteDialogOpen.value = true;
}

function roleHasPermission(
    role: OrganizationRoleDefinition,
    permission: OrganizationPermissionOption,
): boolean {
    return role.permissions.includes(permission.code);
}
</script>

<template>
    <section
        class="space-y-4"
        data-test="organization-roles-section"
        aria-labelledby="roles-heading"
    >
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 id="roles-heading" class="text-base font-semibold">
                    Roles and permissions
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Review what each role can do in this organization.
                </p>
            </div>

            <Button
                v-if="canManageRoles"
                data-test="create-role-button"
                @click="openCreate"
            >
                <Plus />
                New role
            </Button>
        </div>

        <WorkspaceState
            v-if="!customRolesEnabled"
            data-test="roles-entitlement-feedback"
            variant="entitlement"
            title="Custom roles are not included in this plan"
            description="Built-in roles remain available. Ask the organization owner about a plan with custom roles to create your own permission sets."
        />

        <div
            v-else-if="!canManageRoles"
            class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
            data-test="roles-permission-feedback"
        >
            You can review the organization roles. Role changes require
            member-management permission.
        </div>

        <div class="relative max-w-sm">
            <Search
                class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                aria-hidden="true"
            />
            <Input
                v-model="search"
                type="search"
                aria-label="Search roles"
                placeholder="Find a role..."
                class="pl-9"
            />
        </div>
        <WorkspaceState
            v-if="filteredRoles.length === 0"
            variant="empty"
            title="No matching roles"
            description="Try a different name to find the role."
        >
            <template #action
                ><Button variant="outline" @click="search = ''"
                    >Clear search</Button
                ></template
            >
        </WorkspaceState>
        <div v-else class="divide-y overflow-hidden rounded-lg border bg-card">
            <div
                v-for="role in filteredRoles"
                :key="role.code"
                class="p-4"
                data-test="role-row"
            >
                <div
                    class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div class="min-w-0 space-y-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <ShieldCheck
                                class="h-4 w-4 text-muted-foreground"
                            />
                            <p class="font-medium">{{ role.name }}</p>
                            <Badge v-if="role.is_system" variant="secondary">
                                Built-in
                            </Badge>
                            <Badge v-else variant="outline">Custom</Badge>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            {{ role.members_count }}
                            {{
                                role.members_count === 1 ? 'member' : 'members'
                            }}
                            assigned
                        </p>
                    </div>

                    <div
                        v-if="!role.is_system && canManageRoles"
                        class="flex shrink-0 items-center gap-2"
                    >
                        <Button
                            variant="outline"
                            size="sm"
                            data-test="edit-role-button"
                            :aria-label="`Edit ${role.name}`"
                            @click="openEdit(role)"
                        >
                            <Pencil />
                            Edit
                        </Button>
                        <Button
                            v-if="role.members_count === 0"
                            variant="ghost"
                            size="sm"
                            data-test="delete-role-button"
                            :aria-label="`Delete ${role.name}`"
                            @click="deleteRole(role)"
                        >
                            <Trash2 />
                            Delete
                        </Button>
                        <span
                            v-else
                            class="text-xs text-muted-foreground"
                            data-test="role-in-use"
                        >
                            In use
                        </span>
                    </div>
                </div>

                <details class="mt-3">
                    <summary
                        class="min-h-10 w-fit cursor-pointer rounded-sm py-3 text-xs font-medium text-muted-foreground focus-visible:outline-2 focus-visible:outline-ring"
                    >
                        {{ role.permissions.length }}
                        {{
                            role.permissions.length === 1
                                ? 'permission'
                                : 'permissions'
                        }}
                    </summary>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <Badge
                            v-for="permission in availablePermissions.filter(
                                (option) => roleHasPermission(role, option),
                            )"
                            :key="permission.code"
                            variant="outline"
                        >
                            {{ permission.name }}
                        </Badge>
                        <span
                            v-if="role.permissions.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            No permissions assigned
                        </span>
                    </div>
                </details>
            </div>
        </div>

        <Dialog :open="editorOpen" @update:open="editorOpen = $event">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <Form
                    :key="formKey"
                    :action="formAction"
                    :method="formMethod"
                    class="space-y-6"
                    v-slot="{ errors, processing }"
                    @success="closeEditor"
                >
                    <DialogHeader>
                        <DialogTitle>{{ editorTitle }}</DialogTitle>
                        <DialogDescription>
                            {{ editorDescription }}
                        </DialogDescription>
                    </DialogHeader>

                    <ValidationSummary
                        :errors="errors"
                        title="Check the role details"
                        :field-ids="{
                            name: 'role-name',
                            permissions: 'role-permissions',
                        }"
                    />

                    <div class="grid gap-2">
                        <Label for="role-name">Role name</Label>
                        <Input
                            id="role-name"
                            name="name"
                            :default-value="editingRole?.name ?? ''"
                            placeholder="Department coordinator"
                            required
                            :aria-invalid="Boolean(errors.name)"
                            aria-describedby="role-name-error"
                        />
                        <InputError
                            id="role-name-error"
                            :message="errors.name"
                        />
                    </div>

                    <fieldset
                        id="role-permissions"
                        tabindex="-1"
                        class="min-w-0 space-y-3"
                    >
                        <legend class="text-sm font-medium">Permissions</legend>
                        <p class="text-sm text-muted-foreground">
                            Select only the access this role needs.
                        </p>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label
                                v-for="permission in availablePermissions"
                                :key="permission.code"
                                class="flex items-start gap-3 rounded-md border p-3 text-sm transition-colors has-[:checked]:border-primary has-[:checked]:bg-primary/5"
                            >
                                <input
                                    type="checkbox"
                                    name="permissions[]"
                                    :value="permission.code"
                                    :checked="
                                        editingRole
                                            ? roleHasPermission(
                                                  editingRole,
                                                  permission,
                                              )
                                            : false
                                    "
                                    class="mt-0.5 h-4 w-4 rounded border-input text-primary accent-primary"
                                />
                                <span>
                                    <span class="block font-medium">
                                        {{ permission.name }}
                                    </span>
                                    <span class="text-xs text-muted-foreground">
                                        {{ permission.module }}
                                    </span>
                                </span>
                            </label>
                        </div>
                        <InputError :message="errors.permissions" />
                    </fieldset>

                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="secondary"
                                >Cancel</Button
                            >
                        </DialogClose>
                        <Button
                            type="submit"
                            data-test="save-role-button"
                            :disabled="processing"
                        >
                            {{ editingRole ? 'Save changes' : 'Create role' }}
                        </Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="deleteDialogOpen">
            <DialogContent>
                <Form
                    v-if="roleToDelete"
                    :key="roleToDelete.code"
                    v-bind="
                        destroy.form([organization.slug, roleToDelete.code])
                    "
                    class="space-y-6"
                    v-slot="{ errors, processing }"
                    @success="deleteDialogOpen = false"
                >
                    <DialogHeader>
                        <DialogTitle>Delete custom role</DialogTitle>
                        <DialogDescription
                            >Delete "{{ roleToDelete.name }}" and its permission
                            set? Only roles with no assigned members can be
                            deleted.</DialogDescription
                        >
                    </DialogHeader>
                    <ValidationSummary
                        :errors="errors"
                        title="Role could not be deleted"
                    />
                    <DialogFooter class="gap-2">
                        <DialogClose as-child
                            ><Button type="button" variant="secondary"
                                >Keep role</Button
                            ></DialogClose
                        >
                        <Button
                            type="submit"
                            variant="destructive"
                            data-test="delete-role-confirm"
                            :disabled="processing"
                            >{{
                                processing ? 'Deleting...' : 'Delete role'
                            }}</Button
                        >
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>
    </section>
</template>
