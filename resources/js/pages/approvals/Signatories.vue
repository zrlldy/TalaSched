<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { BadgeCheck, CalendarDays, ImagePlus, Pencil, UserRound } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import WorkspacePageHeader from '@/components/WorkspacePageHeader.vue';
import WorkspaceState from '@/components/WorkspaceState.vue';
import { inbox, signatories } from '@/routes/approvals';
import { store, update } from '@/routes/approvals/signatories';
import type { Organization } from '@/types';

type Profile = {
    id: string;
    membership_id: string;
    user_name: string;
    user_email: string;
    name: string;
    position: string;
    academic_unit_id: string | null;
    academic_unit_name: string | null;
    valid_from: string | null;
    valid_until: string | null;
    has_signature: boolean;
};
type Props = {
    profiles: Profile[];
    members: { id: string; name: string; email: string }[];
    units: { id: string; name: string }[];
};

const props = defineProps<Props>();
const page = usePage();
const organization = computed(() => page.props.currentOrganization as Organization | null);
const formatDate = (value: string | null): string =>
    value === null
        ? 'Open-ended'
        : new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', year: 'numeric' }).format(new Date(value + 'T00:00:00'));
const validityLabel = (profile: Profile): string =>
    formatDate(profile.valid_from) + ' → ' + formatDate(profile.valid_until);

defineOptions({
    layout: (layoutProps: { currentOrganization?: Organization | null }) => ({
        breadcrumbs: [
            { title: 'Approvals', href: inbox(layoutProps.currentOrganization?.slug ?? '').url },
            { title: 'Signatories', href: signatories(layoutProps.currentOrganization?.slug ?? '').url },
        ],
    }),
});
</script>

<template>
    <Head title="Signatory profiles" />
    <div class="space-y-8">
        <WorkspacePageHeader section="Approvals" title="Signatory profiles" description="Maintain the names, roles, validity windows, and private signature assets used for future approval snapshots.">
            <template #metadata><span>{{ props.profiles.length }} active profile{{ props.profiles.length === 1 ? '' : 's' }}</span><span>Images remain private</span></template>
        </WorkspacePageHeader>

        <section class="rounded-lg border bg-card p-5 sm:p-7">
            <div class="flex items-start gap-3"><div class="grid size-10 shrink-0 place-items-center rounded-md border border-schedule/30 bg-schedule/10 text-schedule"><ImagePlus class="size-5" /></div><div><h2 class="text-lg font-semibold">Add a signatory profile</h2><p class="mt-1 text-sm leading-6 text-muted-foreground">Validity is inclusive. A replacement image is stored privately and only its historical snapshot is visible in the inbox.</p></div></div>
            <Form v-bind="store.form(organization?.slug ?? '')" class="mt-6 grid gap-4 lg:grid-cols-2" enctype="multipart/form-data" v-slot="{ errors, processing }">
                <div class="grid gap-2"><Label for="profile-member">Organization member</Label><select id="profile-member" name="membership_id" required class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"><option value="">Select a member</option><option v-for="member in props.members" :key="member.id" :value="member.id">{{ member.name }} · {{ member.email }}</option></select><p v-if="errors.membership_id" class="text-sm text-destructive">{{ errors.membership_id }}</p></div>
                <div class="grid gap-2"><Label for="profile-name">Signatory name</Label><Input id="profile-name" name="name" required placeholder="Name shown on approvals" /><p v-if="errors.name" class="text-sm text-destructive">{{ errors.name }}</p></div>
                <div class="grid gap-2"><Label for="profile-position">Position</Label><Input id="profile-position" name="position" required placeholder="Registrar" /><p v-if="errors.position" class="text-sm text-destructive">{{ errors.position }}</p></div>
                <div class="grid gap-2"><Label for="profile-unit">Academic unit</Label><select id="profile-unit" name="academic_unit_id" class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"><option value="">Organization-wide</option><option v-for="unit in props.units" :key="unit.id" :value="unit.id">{{ unit.name }}</option></select></div>
                <div class="grid gap-2"><Label for="profile-from">Valid from</Label><Input id="profile-from" name="valid_from" type="date" /></div>
                <div class="grid gap-2"><Label for="profile-until">Valid until</Label><Input id="profile-until" name="valid_until" type="date" /><p v-if="errors.valid_until" class="text-sm text-destructive">{{ errors.valid_until }}</p></div>
                <div class="grid gap-2 lg:col-span-2"><Label for="profile-image">Signature image <span class="font-normal text-muted-foreground">(JPG, PNG, or WEBP · 2 MB max)</span></Label><Input id="profile-image" name="signature_image" type="file" accept="image/jpeg,image/png,image/webp" /><p v-if="errors.signature_image" class="text-sm text-destructive">{{ errors.signature_image }}</p></div>
                <div class="lg:col-span-2"><Button type="submit" :disabled="processing"><BadgeCheck /> Save signatory profile</Button></div>
            </Form>
        </section>

        <WorkspaceState v-if="props.profiles.length === 0" variant="empty" title="No signatory profiles yet" description="Add a profile above before approvers begin signing workflow decisions." />
        <div v-else class="grid gap-5 lg:grid-cols-2">
            <details v-for="profile in props.profiles" :key="profile.id" class="group rounded-lg border bg-card p-5">
                <summary class="flex cursor-pointer list-none items-start justify-between gap-4">
                    <div class="flex items-start gap-3"><div class="grid size-10 shrink-0 place-items-center rounded-md border border-schedule/25 bg-schedule/10 text-schedule"><UserRound class="size-5" /></div><div><h2 class="font-semibold">{{ profile.name }}</h2><p class="mt-1 text-sm text-muted-foreground">{{ profile.position }} · {{ profile.user_name }}</p><p class="mt-2 text-xs text-muted-foreground">{{ profile.academic_unit_name ?? 'Organization-wide' }} · {{ validityLabel(profile) }}</p></div></div>
                    <Pencil class="mt-1 size-4 text-muted-foreground transition group-open:rotate-45" />
                </summary>
                <div class="mt-5 border-t pt-5">
                    <div class="mb-5 flex flex-wrap gap-2"><Badge variant="outline" class="border-schedule/30 bg-schedule/10 text-schedule">{{ profile.has_signature ? 'Private signature attached' : 'No signature image' }}</Badge><Badge variant="outline"><CalendarDays /> {{ validityLabel(profile) }}</Badge></div>
                    <Form v-bind="update.form([organization?.slug ?? '', profile.id])" class="grid gap-4 sm:grid-cols-2" enctype="multipart/form-data" v-slot="{ errors, processing }">
                        <div class="grid gap-2"><Label :for="'edit-name-' + profile.id">Signatory name</Label><Input :id="'edit-name-' + profile.id" name="name" :default-value="profile.name" required /><p v-if="errors.name" class="text-sm text-destructive">{{ errors.name }}</p></div>
                        <div class="grid gap-2"><Label :for="'edit-position-' + profile.id">Position</Label><Input :id="'edit-position-' + profile.id" name="position" :default-value="profile.position" required /><p v-if="errors.position" class="text-sm text-destructive">{{ errors.position }}</p></div>
                        <div class="grid gap-2"><Label :for="'edit-unit-' + profile.id">Academic unit</Label><select :id="'edit-unit-' + profile.id" name="academic_unit_id" class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"><option value="">Organization-wide</option><option v-for="unit in props.units" :key="unit.id" :value="unit.id" :selected="unit.id === profile.academic_unit_id">{{ unit.name }}</option></select></div>
                        <div class="grid gap-2"><Label :for="'edit-from-' + profile.id">Valid from</Label><Input :id="'edit-from-' + profile.id" name="valid_from" type="date" :default-value="profile.valid_from ?? undefined" /></div>
                        <div class="grid gap-2"><Label :for="'edit-until-' + profile.id">Valid until</Label><Input :id="'edit-until-' + profile.id" name="valid_until" type="date" :default-value="profile.valid_until ?? undefined" /><p v-if="errors.valid_until" class="text-sm text-destructive">{{ errors.valid_until }}</p></div>
                        <div class="grid gap-2"><Label :for="'edit-image-' + profile.id">Replace signature image</Label><Input :id="'edit-image-' + profile.id" name="signature_image" type="file" accept="image/jpeg,image/png,image/webp" /></div>
                        <div class="sm:col-span-2"><Button type="submit" :disabled="processing"><BadgeCheck /> Update profile</Button></div>
                    </Form>
                </div>
            </details>
        </div>
    </div>
</template>
