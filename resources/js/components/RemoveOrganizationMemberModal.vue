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
import { destroy as destroyMember } from '@/routes/organizations/members';
import type { Organization, OrganizationMember } from '@/types';

type Props = {
    organization: Organization;
    member: OrganizationMember | null;
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
                v-if="props.member"
                :key="props.member.id"
                v-bind="
                    destroyMember.form([
                        props.organization.slug,
                        props.member.id,
                    ])
                "
                class="space-y-6"
                v-slot="{ errors, processing }"
                @success="emit('update:open', false)"
            >
                <DialogHeader>
                    <DialogTitle>Remove organization member</DialogTitle>
                    <DialogDescription>
                        Are you sure you want to remove
                        <strong>{{ props.member?.name }}</strong> from this
                        organization?
                        <span class="mt-2 block"
                            >This removes their organization access. They will
                            need a new invitation to rejoin.</span
                        >
                    </DialogDescription>
                </DialogHeader>
                <ValidationSummary
                    :errors="errors"
                    title="Member could not be removed"
                />

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="secondary">
                            Cancel
                        </Button>
                    </DialogClose>

                    <Button
                        data-test="remove-member-confirm"
                        variant="destructive"
                        :disabled="processing"
                        type="submit"
                    >
                        Remove member
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
