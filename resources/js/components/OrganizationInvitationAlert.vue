<script setup lang="ts">
import { Info } from '@lucide/vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import type { OrganizationInvitationContext } from '@/types';

type Props = {
    invitation: OrganizationInvitationContext;
    action: 'Log in' | 'Register';
};

defineProps<Props>();
</script>

<template>
    <div data-test="organization-invitation-alert">
        <Alert
            class="border-schedule/25 bg-schedule/10 text-accent-foreground [&>svg]:text-schedule"
        >
            <Info class="size-4" />
            <AlertDescription class="text-accent-foreground">
                {{ action }} to join the "{{ invitation.organizationName }}"
                organization.
            </AlertDescription>
        </Alert>
    </div>
</template>
