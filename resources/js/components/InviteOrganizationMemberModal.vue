<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import ValidationSummary from '@/components/ValidationSummary.vue';
import { store as storeInvitation } from '@/routes/organizations/invitations';
import type { RoleOption, Organization } from '@/types';

type Props = {
    organization: Organization;
    availableRoles: RoleOption[];
    open: boolean;
};

const props = defineProps<Props>();
const emit = defineEmits<{
    'update:open': [value: boolean];
    sent: [];
}>();

const inviteRole = ref('member');
const formKey = ref(0);

function handleOpenChange(value: boolean) {
    emit('update:open', value);

    if (!value) {
        inviteRole.value = 'member';
        formKey.value++;
    }
}
</script>

<template>
    <Dialog :open="props.open" @update:open="handleOpenChange">
        <DialogContent>
            <Form
                :key="formKey"
                v-bind="storeInvitation.form(props.organization.slug)"
                class="space-y-6"
                v-slot="{ errors, processing }"
                @success="
                    handleOpenChange(false);
                    emit('sent');
                "
            >
                <DialogHeader>
                    <DialogTitle>Invite a member</DialogTitle>
                    <DialogDescription>
                        Send an invitation to join this organization.
                    </DialogDescription>
                </DialogHeader>

                <ValidationSummary
                    :errors="errors"
                    title="Check the invitation details"
                    :field-ids="{ email: 'invite-email', role: 'invite-role' }"
                />

                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="invite-email">Email address</Label>
                        <Input
                            id="invite-email"
                            name="email"
                            data-test="invite-email"
                            type="email"
                            placeholder="colleague@example.com"
                            required
                            :aria-invalid="Boolean(errors.email)"
                            aria-describedby="invite-email-error"
                        />
                        <InputError
                            id="invite-email-error"
                            :message="errors.email"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="invite-role">Role</Label>
                        <Select
                            v-model="inviteRole"
                            name="role"
                            data-test="invite-role"
                        >
                            <SelectTrigger
                                id="invite-role"
                                class="w-full"
                                :aria-invalid="Boolean(errors.role)"
                                aria-describedby="invite-role-error"
                            >
                                <SelectValue placeholder="Select a role" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="role in props.availableRoles"
                                    :key="role.value"
                                    :value="role.value"
                                >
                                    {{ role.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError
                            id="invite-role-error"
                            :message="errors.role"
                        />
                    </div>
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="secondary">
                            Cancel
                        </Button>
                    </DialogClose>

                    <Button
                        type="submit"
                        data-test="invite-submit"
                        :disabled="processing"
                    >
                        Send invitation
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
