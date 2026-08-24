<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import OrganizationInvitationController from '@/actions/App/Http/Controllers/Organizations/OrganizationInvitationController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { DashboardInvitation } from '@/types';

type Props = {
    invitations: DashboardInvitation[];
};

const props = defineProps<Props>();

const open = ref(true);
const processingId = ref<string | null>(null);

const acceptInvitation = (invitation: DashboardInvitation) => {
    router.visit(
        OrganizationInvitationController.accept({ public_id: invitation.id }),
        {
            onStart: () => (processingId.value = invitation.id),
            onFinish: () => (processingId.value = null),
        },
    );
};

const declineInvitation = (invitation: DashboardInvitation) => {
    router.visit(
        OrganizationInvitationController.decline({ public_id: invitation.id }),
        {
            onStart: () => (processingId.value = invitation.id),
            onFinish: () => (processingId.value = null),
            onSuccess: () => {
                if (props.invitations.length === 1) {
                    open.value = false;
                }
            },
        },
    );
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent data-test="pending-invitations-modal">
            <DialogHeader>
                <DialogTitle>Pending organization invitations</DialogTitle>
                <DialogDescription>
                    Accept or decline the organizations you have been invited to
                    join.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-4">
                <div
                    v-for="invitation in props.invitations"
                    :key="invitation.id"
                    data-test="pending-invitation-row"
                    class="rounded-lg border p-4"
                >
                    <div class="space-y-1">
                        <p class="font-medium">
                            {{ invitation.organization.name }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ invitation.inviterName }} invited you to join
                            this organization.
                        </p>
                    </div>

                    <div class="mt-4 flex justify-end gap-2">
                        <Button
                            variant="secondary"
                            data-test="pending-invitation-decline"
                            :disabled="processingId === invitation.id"
                            @click="declineInvitation(invitation)"
                        >
                            Decline
                        </Button>

                        <Button
                            data-test="pending-invitation-accept"
                            :disabled="processingId === invitation.id"
                            @click="acceptInvitation(invitation)"
                        >
                            Accept
                        </Button>
                    </div>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
