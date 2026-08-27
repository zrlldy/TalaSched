<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    CalendarRange,
    PanelsTopLeft,
    Sparkles,
} from '@lucide/vue';
import { computed } from 'vue';
import PendingOrganizationInvitationsModal from '@/components/PendingOrganizationInvitationsModal.vue';
import { dashboard } from '@/routes';
import { setup as academicSetup } from '@/routes/academic';
import { index as organizations } from '@/routes/organizations';
import { setup as resourceSetup } from '@/routes/resources';
import type { DashboardInvitation, Organization } from '@/types';

defineProps<{
    pendingInvitations?: DashboardInvitation[];
}>();

defineOptions({
    layout: (props: { currentOrganization?: Organization | null }) => ({
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: props.currentOrganization
                    ? dashboard(props.currentOrganization.slug)
                    : '/',
            },
        ],
    }),
});

const page = usePage();
const organization = computed(() => page.props.currentOrganization);
const organizationName = computed(
    () => organization.value?.name ?? 'your workspace',
);

const academicSetupUrl = computed(() =>
    organization.value
        ? academicSetup(organization.value.slug).url
        : organizations().url,
);

const resourceSetupUrl = computed(() =>
    organization.value
        ? resourceSetup(organization.value.slug).url
        : organizations().url,
);
</script>

<template>
    <Head title="Dashboard" />

    <PendingOrganizationInvitationsModal
        v-if="pendingInvitations && pendingInvitations.length > 0"
        :invitations="pendingInvitations"
    />

    <div class="min-h-full bg-background p-4 sm:p-6">
        <div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
            <section
                class="relative isolate overflow-hidden rounded-lg border border-sidebar-border bg-sidebar px-5 py-6 text-sidebar-foreground sm:px-7 sm:py-8"
            >
                <div
                    aria-hidden="true"
                    class="absolute -top-24 right-[-3rem] -z-10 size-64 rounded-full border-[1.25rem] border-sidebar-primary/15"
                />
                <div
                    aria-hidden="true"
                    class="absolute -bottom-40 left-1/2 -z-10 size-72 rounded-full border border-sidebar-primary/10"
                />

                <div
                    class="relative flex flex-col justify-between gap-10 lg:flex-row lg:items-end"
                >
                    <div class="max-w-2xl">
                        <p
                            class="mb-3 font-mono text-[0.6875rem] font-semibold tracking-[0.2em] text-sidebar-primary uppercase"
                        >
                            Workspace overview
                        </p>
                        <h1
                            class="max-w-xl text-2xl leading-tight font-semibold tracking-[-0.025em] sm:text-3xl"
                        >
                            Build a schedule people can trust.
                        </h1>
                        <p
                            class="mt-3 max-w-xl text-sm leading-6 text-sidebar-foreground/70 sm:text-[0.9375rem]"
                        >
                            Set up {{ organizationName }} in a clear sequence,
                            then keep every timetable decision grounded in the
                            structure you define here.
                        </p>
                    </div>

                    <div
                        class="hidden w-full max-w-xs shrink-0 rounded-lg border border-sidebar-border bg-sidebar-accent/50 p-4 lg:block"
                    >
                        <p
                            class="font-mono text-[0.625rem] font-semibold tracking-[0.2em] text-sidebar-foreground/55 uppercase"
                        >
                            Setup flow
                        </p>
                        <div class="mt-4 flex flex-col gap-3">
                            <div class="flex items-center gap-3">
                                <span
                                    class="flex size-8 items-center justify-center rounded-md bg-sidebar-primary font-mono text-xs font-semibold text-sidebar-primary-foreground"
                                >
                                    01
                                </span>
                                <span class="text-sm text-sidebar-foreground"
                                    >Academic structure</span
                                >
                            </div>
                            <div
                                class="ml-4 h-4 border-l border-dashed border-sidebar-border"
                            />
                            <div class="flex items-center gap-3">
                                <span
                                    class="flex size-8 items-center justify-center rounded-md border border-sidebar-border font-mono text-xs font-semibold text-sidebar-foreground/60"
                                >
                                    02
                                </span>
                                <span class="text-sm text-sidebar-foreground/70"
                                    >Resources and catalog</span
                                >
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section
                v-if="organization"
                aria-labelledby="start-heading"
                class="grid gap-4 lg:grid-cols-2"
            >
                <article
                    class="group rounded-lg border border-border bg-card p-5 transition-colors hover:border-schedule/50"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div
                            class="flex size-10 items-center justify-center rounded-md border border-warning/25 bg-warning/10 text-warning"
                        >
                            <CalendarRange class="size-5" />
                        </div>
                        <span
                            class="font-mono text-[0.625rem] font-semibold tracking-[0.18em] text-muted-foreground uppercase"
                            >Step 01</span
                        >
                    </div>
                    <div class="mt-6">
                        <p
                            class="font-mono text-[0.6875rem] font-semibold tracking-[0.16em] text-warning uppercase"
                        >
                            Start here
                        </p>
                        <h2
                            id="start-heading"
                            class="mt-2 text-lg font-semibold tracking-[-0.02em] text-foreground"
                        >
                            Define the academic structure
                        </h2>
                        <p
                            class="mt-2 max-w-lg text-sm leading-6 text-muted-foreground"
                        >
                            Add the academic year, periods, operating hours, and
                            exceptions that shape every schedule.
                        </p>
                    </div>
                    <Link
                        :href="academicSetupUrl"
                        class="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-schedule transition group-hover:gap-3"
                    >
                        Open academic setup
                        <ArrowRight class="size-4" />
                    </Link>
                </article>

                <article
                    class="group rounded-lg border border-border bg-card p-5 transition-colors hover:border-available/50"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div
                            class="flex size-10 items-center justify-center rounded-md border border-available/25 bg-available/10 text-available"
                        >
                            <PanelsTopLeft class="size-5" />
                        </div>
                        <span
                            class="font-mono text-[0.625rem] font-semibold tracking-[0.18em] text-muted-foreground uppercase"
                            >Step 02</span
                        >
                    </div>
                    <div class="mt-6">
                        <p
                            class="font-mono text-[0.6875rem] font-semibold tracking-[0.16em] text-available uppercase"
                        >
                            Next up
                        </p>
                        <h2
                            class="mt-2 text-lg font-semibold tracking-[-0.02em] text-foreground"
                        >
                            Add the pieces of your timetable
                        </h2>
                        <p
                            class="mt-2 max-w-lg text-sm leading-6 text-muted-foreground"
                        >
                            Create faculty, rooms, subjects, and offerings so
                            scheduling has reliable resources to work with.
                        </p>
                    </div>
                    <Link
                        :href="resourceSetupUrl"
                        class="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-schedule transition group-hover:gap-3"
                    >
                        Open resources and catalog
                        <ArrowRight class="size-4" />
                    </Link>
                </article>
            </section>

            <section
                v-else
                class="rounded-lg border border-dashed border-border bg-card p-7 text-center"
            >
                <div
                    class="mx-auto flex size-11 items-center justify-center rounded-md border border-schedule/25 bg-schedule/10 text-schedule"
                >
                    <Sparkles class="size-5" />
                </div>
                <h2
                    class="mt-4 text-lg font-semibold tracking-[-0.02em] text-foreground"
                >
                    Choose a workspace to get started
                </h2>
                <p
                    class="mx-auto mt-2 max-w-lg text-sm leading-6 text-muted-foreground"
                >
                    Select an existing institution or create a new one before
                    setting up its academic calendar and scheduling resources.
                </p>
                <Link
                    :href="organizations().url"
                    class="mt-5 inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground transition hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                >
                    View workspaces
                    <ArrowRight class="size-4" />
                </Link>
            </section>

            <section
                aria-labelledby="principles-heading"
                class="grid gap-4 rounded-lg border border-border bg-muted/30 p-5 sm:grid-cols-[1fr_auto] sm:items-center sm:p-6"
            >
                <div>
                    <p
                        class="font-mono text-[0.625rem] font-semibold tracking-[0.2em] text-muted-foreground uppercase"
                    >
                        A better starting point
                    </p>
                    <h2
                        id="principles-heading"
                        class="mt-2 text-lg font-semibold tracking-[-0.02em] text-foreground"
                    >
                        Clear inputs make calmer scheduling.
                    </h2>
                    <p
                        class="mt-2 max-w-2xl text-sm leading-6 text-muted-foreground"
                    >
                        TalaSched keeps setup decisions visible and reusable, so
                        your team can spend less time translating rules and more
                        time improving the timetable.
                    </p>
                </div>
                <div
                    class="flex size-12 items-center justify-center rounded-md border border-schedule/25 bg-sidebar text-sidebar-primary"
                    aria-hidden="true"
                >
                    <Sparkles class="size-6" />
                </div>
            </section>
        </div>
    </div>
</template>
