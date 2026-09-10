<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
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
import ValidationSummary from '@/components/ValidationSummary.vue';
import { destroy as destroyInvitation } from '@/routes/organizations/invitations';
import type { Organization, OrganizationInvitation } from '@/types';

type Props = {
    organization: Organization;
    invitation: OrganizationInvitation | null;
    open: boolean;
};

const props = defineProps<Props>();
const emit = defineEmits<{
    'update:open': [value: boolean];
}>();
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent>
            <Form
                v-if="props.invitation"
                :key="props.invitation.id"
                v-bind="
                    destroyInvitation.form([
                        props.organization.slug,
                        props.invitation.id,
                    ])
                "
                class="space-y-6"
                v-slot="{ errors, processing }"
                @success="emit('update:open', false)"
            >
                <DialogHeader>
                    <DialogTitle>Cancel invitation</DialogTitle>
                    <DialogDescription>
                        Are you sure you want to cancel the invitation for
                        <strong>{{ props.invitation?.email }}</strong
                        >?
                        <span class="mt-2 block"
                            >This invitation will no longer allow them to join
                            the organization.</span
                        >
                    </DialogDescription>
                </DialogHeader>
                <ValidationSummary
                    :errors="errors"
                    title="Invitation could not be cancelled"
                />

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="secondary">
                            Keep invitation
                        </Button>
                    </DialogClose>

                    <Button
                        data-test="cancel-invitation-confirm"
                        variant="destructive"
                        :disabled="processing"
                        type="submit"
                    >
                        Cancel invitation
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
