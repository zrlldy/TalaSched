<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import {
    BadgeCheck,
    CalendarDays,
    ImagePlus,
    ChevronDown,
    Plus,
    Search,
    UserRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ApprovalNavigation from '@/components/ApprovalNavigation.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ValidationSummary from '@/components/ValidationSummary.vue';
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
    signature_download_url: string | null;
};
type Props = {
    profiles: Profile[];
    members: { id: string; name: string; email: string }[];
    units: { id: string; name: string }[];
};

const props = defineProps<Props>();
const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as Organization | null,
);
const section = ref<'directory' | 'create'>('directory');
const search = ref('');
const visibleProfiles = computed(() => {
    const query = search.value.trim().toLowerCase();

    return props.profiles.filter((profile) =>
        [
            profile.name,
            profile.position,
            profile.user_name,
            profile.academic_unit_name ?? '',
        ].some((value) => value.toLowerCase().includes(query)),
    );
});
const saved = (): void => {
    section.value = 'directory';
    search.value = '';
};
const formatDate = (value: string | null): string =>
    value === null
        ? 'Open-ended'
        : new Intl.DateTimeFormat(undefined, {
              month: 'short',
              day: 'numeric',
              year: 'numeric',
          }).format(new Date(value + 'T00:00:00'));
const validityLabel = (profile: Profile): string =>
    formatDate(profile.valid_from) + ' → ' + formatDate(profile.valid_until);

defineOptions({
    layout: (layoutProps: { currentOrganization?: Organization | null }) => ({
        breadcrumbs: [
            {
                title: 'Approvals',
                href: inbox(layoutProps.currentOrganization?.slug ?? '').url,
            },
            {
                title: 'Signatories',
                href: signatories(layoutProps.currentOrganization?.slug ?? '')
                    .url,
            },
        ],
    }),
});
</script>

<template>
    <div class="mx-auto w-full max-w-7xl min-w-0 space-y-5 p-4 sm:p-6">
        <Head title="Signatory profiles" />
        <WorkspacePageHeader
            section="Review & publish"
            title="Signatory profiles"
            description="Manage the names and signatures that appear on approved timetables."
        >
            <template #actions
                ><Button @click="section = 'create'"
                    ><Plus /> Add profile</Button
                ></template
            >
        </WorkspacePageHeader>
        <ApprovalNavigation
            :organization-slug="organization?.slug ?? ''"
            current="signatories"
            :can-manage="true"
        />
        <div
            class="flex flex-wrap gap-2"
            role="group"
            aria-label="Signatory sections"
        >
            <Button
                :variant="section === 'directory' ? 'secondary' : 'ghost'"
                :aria-pressed="section === 'directory'"
                @click="section = 'directory'"
                ><UserRound /> Saved profiles
                <span class="font-schedule text-xs">{{
                    profiles.length
                }}</span></Button
            >
            <Button
                :variant="section === 'create' ? 'secondary' : 'ghost'"
                :aria-pressed="section === 'create'"
                @click="section = 'create'"
                ><Plus /> New profile</Button
            >
        </div>

        <section
            v-show="section === 'create'"
            class="min-w-0 rounded-lg border bg-card p-5 sm:p-6"
        >
            <div class="flex min-w-0 items-start gap-3">
                <div
                    class="grid size-10 shrink-0 place-items-center rounded-md border border-schedule/30 bg-schedule/10 text-schedule"
                >
                    <ImagePlus class="size-5" />
                </div>
                <div>
                    <h2 class="text-lg font-semibold">
                        Add a signatory profile
                    </h2>
                    <p class="mt-1 text-sm leading-6 text-muted-foreground">
                        Choose a member and enter their signing details. Changes
                        apply to future approvals; past decisions keep their
                        original details.
                    </p>
                </div>
            </div>
            <Form
                v-bind="store.form(organization?.slug ?? '')"
                class="mt-6 grid gap-4 lg:grid-cols-2"
                enctype="multipart/form-data"
                v-slot="{ errors, processing }"
                reset-on-success
                @success="saved"
            >
                <ValidationSummary
                    :errors="errors"
                    title="Check the signatory details"
                    class="lg:col-span-2"
                    :field-ids="{
                        membership_id: 'profile-member',
                        name: 'profile-name',
                        position: 'profile-position',
                        academic_unit_id: 'profile-unit',
                        valid_from: 'profile-from',
                        valid_until: 'profile-until',
                        signature_image: 'profile-image',
                    }"
                />
                <div class="grid gap-2">
                    <Label for="profile-member">Organization member</Label
                    ><select
                        id="profile-member"
                        name="membership_id"
                        required
                        class="h-9 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <option value="">Select a member</option>
                        <option
                            v-for="member in props.members"
                            :key="member.id"
                            :value="member.id"
                        >
                            {{ member.name }} · {{ member.email }}
                        </option>
                    </select>
                    <p
                        v-if="errors.membership_id"
                        class="text-sm text-destructive"
                    >
                        {{ errors.membership_id }}
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="profile-name">Signatory name</Label
                    ><Input
                        id="profile-name"
                        name="name"
                        required
                        placeholder="Name shown on approvals"
                    />
                    <p v-if="errors.name" class="text-sm text-destructive">
                        {{ errors.name }}
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="profile-position">Position</Label
                    ><Input
                        id="profile-position"
                        name="position"
                        required
                        placeholder="Registrar"
                    />
                    <p v-if="errors.position" class="text-sm text-destructive">
                        {{ errors.position }}
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="profile-unit">Academic unit</Label
                    ><select
                        id="profile-unit"
                        name="academic_unit_id"
                        class="h-9 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <option value="">Organization-wide</option>
                        <option
                            v-for="unit in props.units"
                            :key="unit.id"
                            :value="unit.id"
                        >
                            {{ unit.name }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label for="profile-from">Valid from</Label
                    ><Input id="profile-from" name="valid_from" type="date" />
                </div>
                <div class="grid gap-2">
                    <Label for="profile-until">Valid until</Label
                    ><Input id="profile-until" name="valid_until" type="date" />
                    <p
                        v-if="errors.valid_until"
                        class="text-sm text-destructive"
                    >
                        {{ errors.valid_until }}
                    </p>
                </div>
                <div class="grid gap-2 lg:col-span-2">
                    <Label for="profile-image"
                        >Signature image
                        <span class="font-normal text-muted-foreground"
                            >(JPG, PNG, or WEBP · 2 MB max)</span
                        ></Label
                    ><Input
                        id="profile-image"
                        name="signature_image"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                    />
                    <p
                        v-if="errors.signature_image"
                        class="text-sm text-destructive"
                    >
                        {{ errors.signature_image }}
                    </p>
                </div>
                <div class="lg:col-span-2">
                    <Button type="submit" :disabled="processing"
                        ><BadgeCheck /> Save signatory profile</Button
                    >
                </div>
            </Form>
        </section>

        <section
            v-show="section === 'directory'"
            class="min-w-0 space-y-4"
            aria-label="Signatory directory"
        >
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <h2 class="text-base font-semibold">People who sign</h2>
                    <p class="mt-1 text-sm break-words text-muted-foreground">
                        Open a profile to update its details or signature.
                    </p>
                </div>
                <div v-if="profiles.length" class="relative w-full sm:max-w-72">
                    <Search
                        class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                    /><Input
                        v-model="search"
                        type="search"
                        aria-label="Search signatory profiles"
                        placeholder="Find a name, position, or unit..."
                        class="pl-9"
                    />
                </div>
            </div>
            <WorkspaceState
                v-if="props.profiles.length === 0"
                variant="empty"
                title="No signatory profiles yet"
                description="Add an approver's name, position, and optional signature image for future timetable approvals."
                ><template #action
                    ><Button @click="section = 'create'"
                        ><Plus /> Add a signatory</Button
                    ></template
                ></WorkspaceState
            >
            <WorkspaceState
                v-else-if="visibleProfiles.length === 0"
                variant="empty"
                title="No matching profiles"
                description="Try another name, position, or academic unit."
                ><template #action
                    ><Button variant="outline" @click="search = ''"
                        >Clear search</Button
                    ></template
                ></WorkspaceState
            >
            <div
                v-else
                class="divide-y overflow-hidden rounded-lg border bg-card"
            >
                <details
                    v-for="profile in visibleProfiles"
                    :key="profile.id"
                    class="group min-w-0"
                >
                    <summary
                        class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 p-4 hover:bg-muted/30 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                    >
                        <div class="flex min-w-0 items-start gap-3">
                            <div
                                class="grid size-10 shrink-0 place-items-center rounded-md border border-schedule/25 bg-schedule/10 text-schedule"
                            >
                                <UserRound class="size-5" />
                            </div>
                            <div class="min-w-0">
                                <h3 class="font-semibold break-words">
                                    {{ profile.name }}
                                </h3>
                                <p
                                    class="mt-1 text-sm break-words text-muted-foreground"
                                >
                                    {{ profile.position }} ·
                                    {{ profile.user_name }}
                                </p>
                                <p class="mt-2 text-xs text-muted-foreground">
                                    {{
                                        profile.academic_unit_name ??
                                        'Organization-wide'
                                    }}
                                    · {{ validityLabel(profile) }}
                                </p>
                            </div>
                        </div>
                        <span class="flex items-center gap-3 pl-13 sm:pl-0"
                            ><Badge
                                variant="outline"
                                :class="
                                    profile.has_signature
                                        ? 'border-available/30 bg-available/10 text-available'
                                        : 'border-warning/30 bg-warning/10 text-foreground'
                                "
                                >{{
                                    profile.has_signature
                                        ? 'Signature attached'
                                        : 'No signature image'
                                }}</Badge
                            ><ChevronDown
                                class="size-4 text-muted-foreground transition-transform group-open:rotate-180 motion-reduce:transition-none"
                        /></span>
                    </summary>
                    <div class="space-y-4 border-t bg-muted/15 p-4 sm:p-5">
                        <div class="mb-5 flex flex-wrap items-center gap-2">
                            <Badge
                                variant="outline"
                                class="border-schedule/30 bg-schedule/10 text-schedule"
                                >{{
                                    profile.has_signature
                                        ? 'Private signature attached'
                                        : 'No signature image'
                                }}</Badge
                            ><Badge variant="outline"
                                ><CalendarDays />
                                {{ validityLabel(profile) }}</Badge
                            ><a
                                v-if="profile.signature_download_url"
                                :href="profile.signature_download_url"
                                class="text-sm font-medium text-schedule underline-offset-4 hover:underline"
                                >Download signature</a
                            >
                        </div>
                        <Form
                            v-bind="
                                update.form([
                                    organization?.slug ?? '',
                                    profile.id,
                                ])
                            "
                            class="grid gap-4 sm:grid-cols-2"
                            enctype="multipart/form-data"
                            v-slot="{ errors, processing }"
                        >
                            <ValidationSummary
                                :errors="errors"
                                title="Check the profile changes"
                                class="sm:col-span-2"
                                :field-ids="{
                                    name: 'edit-name-' + profile.id,
                                    position: 'edit-position-' + profile.id,
                                    academic_unit_id: 'edit-unit-' + profile.id,
                                    valid_from: 'edit-from-' + profile.id,
                                    valid_until: 'edit-until-' + profile.id,
                                    signature_image: 'edit-image-' + profile.id,
                                }"
                            />
                            <div class="grid gap-2">
                                <Label :for="'edit-name-' + profile.id"
                                    >Signatory name</Label
                                ><Input
                                    :id="'edit-name-' + profile.id"
                                    name="name"
                                    :default-value="profile.name"
                                    required
                                />
                                <p
                                    v-if="errors.name"
                                    class="text-sm text-destructive"
                                >
                                    {{ errors.name }}
                                </p>
                            </div>
                            <div class="grid gap-2">
                                <Label :for="'edit-position-' + profile.id"
                                    >Position</Label
                                ><Input
                                    :id="'edit-position-' + profile.id"
                                    name="position"
                                    :default-value="profile.position"
                                    required
                                />
                                <p
                                    v-if="errors.position"
                                    class="text-sm text-destructive"
                                >
                                    {{ errors.position }}
                                </p>
                            </div>
                            <div class="grid gap-2">
                                <Label :for="'edit-unit-' + profile.id"
                                    >Academic unit</Label
                                ><select
                                    :id="'edit-unit-' + profile.id"
                                    name="academic_unit_id"
                                    class="h-9 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    <option value="">Organization-wide</option>
                                    <option
                                        v-for="unit in props.units"
                                        :key="unit.id"
                                        :value="unit.id"
                                        :selected="
                                            unit.id === profile.academic_unit_id
                                        "
                                    >
                                        {{ unit.name }}
                                    </option>
                                </select>
                            </div>
                            <div class="grid gap-2">
                                <Label :for="'edit-from-' + profile.id"
                                    >Valid from</Label
                                ><Input
                                    :id="'edit-from-' + profile.id"
                                    name="valid_from"
                                    type="date"
                                    :default-value="
                                        profile.valid_from ?? undefined
                                    "
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label :for="'edit-until-' + profile.id"
                                    >Valid until</Label
                                ><Input
                                    :id="'edit-until-' + profile.id"
                                    name="valid_until"
                                    type="date"
                                    :default-value="
                                        profile.valid_until ?? undefined
                                    "
                                />
                                <p
                                    v-if="errors.valid_until"
                                    class="text-sm text-destructive"
                                >
                                    {{ errors.valid_until }}
                                </p>
                            </div>
                            <div class="grid gap-2">
                                <Label :for="'edit-image-' + profile.id"
                                    >Replace signature image</Label
                                ><Input
                                    :id="'edit-image-' + profile.id"
                                    name="signature_image"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                />
                            </div>
                            <div class="sm:col-span-2">
                                <Button type="submit" :disabled="processing"
                                    ><BadgeCheck /> Update profile</Button
                                >
                            </div>
                        </Form>
                    </div>
                </details>
            </div>
            <p
                v-if="profiles.length"
                class="flex items-start gap-2 text-xs leading-5 text-muted-foreground"
            >
                <BadgeCheck class="mt-0.5 size-4 shrink-0" />Signature images
                stay private. Updating a profile does not change past approval
                records.
            </p>
        </section>
    </div>
</template>
