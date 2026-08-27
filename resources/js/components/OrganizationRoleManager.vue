<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import { LockKeyhole, Pencil, Plus, ShieldCheck, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
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
    editorOpen.value = false;
    editingRole.value = null;
    formKey.value++;
}

function deleteRole(role: OrganizationRoleDefinition): void {
    if (!window.confirm('Delete the custom role "' + role.name + '"?')) {
        return;
    }

    router.delete(destroy.url([props.organization.slug, role.code]), {
        preserveScroll: true,
    });
}

function roleHasPermission(
    role: OrganizationRoleDefinition,
    permission: OrganizationPermissionOption,
): boolean {
    return role.permissions.includes(permission.code);
}
</script>

<template>
    <section class="space-y-6" data-test="organization-roles-section">
        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                title="Roles and permissions"
                description="Shape access around the way your organization works."
            />

            <Button
                v-if="canManageRoles"
                data-test="create-role-button"
                @click="openCreate"
            >
                <Plus />
                New role
            </Button>
        </div>

        <div
            v-if="!customRolesEnabled"
            class="flex items-start gap-3 rounded-lg border border-warning/30 bg-warning/10 p-4 text-warning-foreground"
            data-test="roles-entitlement-feedback"
        >
            <LockKeyhole class="mt-0.5 h-4 w-4 shrink-0" />
            <div class="space-y-1 text-sm">
                <p class="font-medium">
                    Custom roles are not included in this plan.
                </p>
                <p class="text-warning-foreground/75">
                    Built-in roles remain available. Upgrade the organization
                    plan to create custom permission sets.
                </p>
            </div>
        </div>

        <div
            v-else-if="!canManageRoles"
            class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
            data-test="roles-permission-feedback"
        >
            You can review the organization roles. Role changes require
            member-management permission.
        </div>

        <div class="grid gap-3">
            <div
                v-for="role in roles"
                :key="role.code"
                class="rounded-lg border p-4"
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

                <div class="mt-4 flex flex-wrap gap-2">
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

                    <div class="grid gap-2">
                        <Label for="role-name">Role name</Label>
                        <Input
                            id="role-name"
                            name="name"
                            :default-value="editingRole?.name ?? ''"
                            placeholder="Department coordinator"
                            required
                        />
                        <InputError :message="errors.name" />
                    </div>

                    <div class="space-y-3">
                        <div>
                            <Label>Permissions</Label>
                            <p class="text-sm text-muted-foreground">
                                Select only the access this role needs.
                            </p>
                        </div>
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
                    </div>

                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button variant="secondary">Cancel</Button>
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
    </section>
</template>
