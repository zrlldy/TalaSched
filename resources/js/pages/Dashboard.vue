<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, Check, CalendarDays } from '@lucide/vue';
import { computed } from 'vue';
import PendingOrganizationInvitationsModal from '@/components/PendingOrganizationInvitationsModal.vue';
import TimetableDirectory from '@/components/TimetableDirectory.vue';
import { Button } from '@/components/ui/button';
import WorkspacePageHeader from '@/components/WorkspacePageHeader.vue';
import WorkspaceState from '@/components/WorkspaceState.vue';
import { dashboard } from '@/routes';
import { setup as academicSetup } from '@/routes/academic';
import { inbox } from '@/routes/approvals';
import { setup as resourceSetup } from '@/routes/resources';
import { index as timetables } from '@/routes/scheduling/timetables';
import type { DashboardInvitation, Organization } from '@/types';
import type { TimetableSummary } from '@/types/timetable-directory';

const props = defineProps<{
    pendingInvitations?: DashboardInvitation[];
    overview: {
        counts: Record<
            | 'years'
            | 'periods'
            | 'groups'
            | 'faculty'
            | 'rooms'
            | 'subjects'
            | 'offerings'
            | 'timetables',
            number
        >;
        recentTimetables: TimetableSummary[];
    };
}>();
defineOptions({
    layout: (props: { currentOrganization: Organization }) => ({
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(props.currentOrganization.slug),
            },
        ],
    }),
});
const page = usePage();
const organization = computed(() => page.props.currentOrganization!);
const canPrepare = computed(() =>
    Object.values(page.props.workspacePermissions).some(Boolean),
);
const steps = computed(() => {
    const counts = props.overview.counts;
    const slug = organization.value.slug;

    return [
        {
            label: 'Academic years & periods',
            detail: 'Define the dates your timetable will cover.',
            count: counts.periods,
            unit: 'periods',
            href: academicSetup(slug, { query: { section: 'years' } }),
            complete: counts.periods > 0,
            allowed: page.props.workspacePermissions.academic,
        },
        {
            label: 'Faculty & rooms',
            detail: 'Add the people and teaching spaces you use.',
            count: counts.faculty + counts.rooms,
            unit: 'resources',
            href: resourceSetup(slug, {
                query: { section: counts.faculty ? 'rooms' : 'faculty' },
            }),
            complete: counts.faculty > 0 && counts.rooms > 0,
            allowed: page.props.workspacePermissions.resources,
        },
        {
            label: 'Subjects & offerings',
            detail: 'Choose what each student group takes this period.',
            count: counts.offerings,
            unit: 'offerings',
            href: resourceSetup(slug, {
                query: { section: counts.subjects ? 'offerings' : 'subjects' },
            }),
            complete: counts.offerings > 0,
            allowed: page.props.workspacePermissions.catalog,
        },
    ].filter((step) => step.allowed);
});
const nextStep = computed(() => steps.value.find((step) => !step.complete));
</script>

<template>
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 sm:p-6">
        <Head title="Dashboard" />
        <PendingOrganizationInvitationsModal
            v-if="pendingInvitations?.length"
            :invitations="pendingInvitations"
        />
        <WorkspacePageHeader
            :title="organization.name"
            section="Workspace overview"
            description="Prepare your school, build the week, and keep track of reviews."
        >
            <template #actions>
                <Button as-child class="min-h-10"
                    ><Link :href="timetables(organization.slug)"
                        ><CalendarDays class="size-4" /> Open timetables</Link
                    ></Button
                >
            </template>
        </WorkspacePageHeader>

        <div class="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <section
                class="min-w-0 space-y-4"
                aria-labelledby="recent-timetables"
            >
                <div class="flex items-center justify-between gap-3">
                    <h2 id="recent-timetables" class="text-lg font-semibold">
                        Your timetables
                    </h2>
                    <Link
                        :href="timetables(organization.slug)"
                        class="inline-flex min-h-10 items-center gap-2 text-sm font-medium text-primary"
                        >View all <ArrowRight class="size-4"
                    /></Link>
                </div>
                <TimetableDirectory
                    v-if="overview.recentTimetables.length"
                    :timetables="overview.recentTimetables"
                    :organization-slug="organization.slug"
                />
                <WorkspaceState
                    v-else
                    variant="empty"
                    title="Your first timetable starts here"
                    :description="
                        canPrepare
                            ? 'Create a timetable for an academic period, then add classes to its first draft.'
                            : 'No timetables have been created yet. Your scheduler will prepare them here.'
                    "
                >
                    <template #action
                        ><Button as-child variant="outline" class="min-h-10"
                            ><Link :href="timetables(organization.slug)"
                                >Go to timetables
                                <ArrowRight class="size-4" /></Link></Button
                    ></template>
                </WorkspaceState>
                <Link
                    :href="inbox(organization.slug)"
                    class="flex items-center justify-between gap-4 rounded-lg border bg-card p-5 hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <div>
                        <h2 class="font-medium">Review a timetable</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Open your approval inbox to review pending decisions
                            and history.
                        </p>
                    </div>
                    <ArrowRight class="size-4 shrink-0 text-muted-foreground" />
                </Link>
            </section>

            <aside
                v-if="canPrepare"
                class="min-w-0 space-y-4"
                aria-labelledby="setup-checklist"
            >
                <h2
                    id="setup-checklist"
                    class="flex min-h-10 items-center text-lg font-semibold"
                >
                    Setup checklist
                </h2>
                <div class="overflow-hidden rounded-lg border bg-card">
                    <div class="border-b bg-muted/40 p-4">
                        <p class="text-sm font-medium">
                            {{
                                nextStep
                                    ? 'Suggested next step'
                                    : 'Your setup is taking shape'
                            }}
                        </p>
                        <p class="mt-1 text-sm leading-6 text-muted-foreground">
                            {{
                                nextStep?.detail ??
                                'Keep your resources up to date as your school changes.'
                            }}
                        </p>
                    </div>
                    <ol class="divide-y divide-border">
                        <li v-for="(step, index) in steps" :key="step.label">
                            <Link
                                :href="step.href"
                                class="flex gap-3 p-4 hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <span
                                    class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full border text-xs"
                                    :class="
                                        step.complete
                                            ? 'border-available/30 bg-available/10 text-available'
                                            : 'border-primary/25 bg-primary/5 text-primary'
                                    "
                                >
                                    <Check
                                        v-if="step.complete"
                                        class="size-4"
                                    /><span v-else>{{ index + 1 }}</span>
                                </span>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium">
                                        {{ step.label }}
                                    </p>
                                    <p
                                        class="mt-1 text-xs leading-5 text-muted-foreground"
                                    >
                                        {{ step.count }} {{ step.unit }} added ·
                                        {{
                                            step.complete
                                                ? 'Review setup'
                                                : 'Continue setup'
                                        }}
                                    </p>
                                </div>
                                <ArrowRight
                                    class="mt-1 size-4 shrink-0 text-muted-foreground"
                                />
                            </Link>
                        </li>
                    </ol>
                </div>
                <p class="px-1 text-xs leading-5 text-muted-foreground">
                    This checklist tracks saved records. Scheduling checks
                    availability and conflicts when you add classes.
                </p>
            </aside>
            <aside
                v-else
                class="rounded-lg border bg-muted/30 p-5 text-sm leading-6"
            >
                <h2 class="font-semibold">Your school workspace</h2>
                <p class="mt-2 text-muted-foreground">
                    Browse timetables and check your approval inbox. Contact
                    your organization administrator when school details need to
                    change.
                </p>
            </aside>
        </div>
    </div>
</template>
