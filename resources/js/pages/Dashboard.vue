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

    <div class="min-h-full bg-[#f4f6fb] p-4 sm:p-6 dark:bg-slate-950">
        <div class="mx-auto flex w-full max-w-7xl flex-col gap-6">
            <section
                class="relative isolate overflow-hidden rounded-[1.75rem] bg-[#07101f] px-6 py-8 text-white shadow-[0_18px_45px_rgba(7,16,31,0.16)] sm:px-8 lg:px-10 lg:py-10"
            >
                <div
                    aria-hidden="true"
                    class="absolute -top-32 right-[-4rem] -z-10 size-80 rounded-full border-[2.25rem] border-[#f2b544]/20"
                />
                <div
                    aria-hidden="true"
                    class="absolute -bottom-40 left-1/2 -z-10 size-72 rounded-full border-[1.5rem] border-[#8bc4be]/10"
                />

                <div
                    class="relative flex flex-col justify-between gap-10 lg:flex-row lg:items-end"
                >
                    <div class="max-w-2xl">
                        <p
                            class="mb-4 font-mono text-[0.7rem] font-bold tracking-[0.28em] text-[#f2b544] uppercase"
                        >
                            Workspace overview
                        </p>
                        <h1
                            class="max-w-xl text-3xl leading-tight font-semibold tracking-[-0.03em] sm:text-4xl lg:text-[3.25rem]"
                        >
                            Build a schedule people can trust.
                        </h1>
                        <p
                            class="mt-4 max-w-xl text-base leading-7 text-slate-300 sm:text-lg"
                        >
                            Set up {{ organizationName }} in a clear sequence,
                            then keep every timetable decision grounded in the
                            structure you define here.
                        </p>
                    </div>

                    <div
                        class="hidden w-full max-w-xs shrink-0 rounded-2xl border border-white/10 bg-white/[0.05] p-4 lg:block"
                    >
                        <p
                            class="font-mono text-[0.65rem] font-bold tracking-[0.24em] text-slate-400 uppercase"
                        >
                            Setup flow
                        </p>
                        <div class="mt-4 flex flex-col gap-3">
                            <div class="flex items-center gap-3">
                                <span
                                    class="flex size-8 items-center justify-center rounded-full bg-[#f2b544] font-mono text-xs font-bold text-[#07101f]"
                                >
                                    01
                                </span>
                                <span class="text-sm text-slate-200"
                                    >Academic structure</span
                                >
                            </div>
                            <div
                                class="ml-4 h-4 border-l border-dashed border-white/20"
                            />
                            <div class="flex items-center gap-3">
                                <span
                                    class="flex size-8 items-center justify-center rounded-full border border-white/20 font-mono text-xs font-bold text-slate-300"
                                >
                                    02
                                </span>
                                <span class="text-sm text-slate-300"
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
                    class="group rounded-2xl border border-[#dfe5ef] bg-white p-6 shadow-[0_8px_24px_rgba(37,53,80,0.05)] transition hover:-translate-y-0.5 hover:shadow-[0_14px_32px_rgba(37,53,80,0.1)] dark:border-slate-800 dark:bg-slate-900"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div
                            class="flex size-11 items-center justify-center rounded-xl bg-[#fff3d9] text-[#d58200] dark:bg-[#4a3512] dark:text-[#f2b544]"
                        >
                            <CalendarRange class="size-5" />
                        </div>
                        <span
                            class="font-mono text-[0.65rem] font-bold tracking-[0.2em] text-slate-400 uppercase"
                            >Step 01</span
                        >
                    </div>
                    <div class="mt-6">
                        <p
                            class="font-mono text-xs font-bold tracking-[0.18em] text-[#d58200] uppercase dark:text-[#f2b544]"
                        >
                            Start here
                        </p>
                        <h2
                            id="start-heading"
                            class="mt-2 text-xl font-semibold tracking-[-0.02em] text-[#26334a] dark:text-slate-100"
                        >
                            Define the academic structure
                        </h2>
                        <p
                            class="mt-2 max-w-lg text-sm leading-6 text-slate-500 dark:text-slate-400"
                        >
                            Add the academic year, periods, operating hours, and
                            exceptions that shape every schedule.
                        </p>
                    </div>
                    <Link
                        :href="academicSetupUrl"
                        class="mt-6 inline-flex items-center gap-2 text-sm font-semibold text-[#285be8] transition group-hover:gap-3 dark:text-blue-400"
                    >
                        Open academic setup
                        <ArrowRight class="size-4" />
                    </Link>
                </article>

                <article
                    class="group rounded-2xl border border-[#dfe5ef] bg-white p-6 shadow-[0_8px_24px_rgba(37,53,80,0.05)] transition hover:-translate-y-0.5 hover:shadow-[0_14px_32px_rgba(37,53,80,0.1)] dark:border-slate-800 dark:bg-slate-900"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div
                            class="flex size-11 items-center justify-center rounded-xl bg-[#e4f4f0] text-[#27756c] dark:bg-[#143a37] dark:text-[#8bc4be]"
                        >
                            <PanelsTopLeft class="size-5" />
                        </div>
                        <span
                            class="font-mono text-[0.65rem] font-bold tracking-[0.2em] text-slate-400 uppercase"
                            >Step 02</span
                        >
                    </div>
                    <div class="mt-6">
                        <p
                            class="font-mono text-xs font-bold tracking-[0.18em] text-[#27756c] uppercase dark:text-[#8bc4be]"
                        >
                            Next up
                        </p>
                        <h2
                            class="mt-2 text-xl font-semibold tracking-[-0.02em] text-[#26334a] dark:text-slate-100"
                        >
                            Add the pieces of your timetable
                        </h2>
                        <p
                            class="mt-2 max-w-lg text-sm leading-6 text-slate-500 dark:text-slate-400"
                        >
                            Create faculty, rooms, subjects, and offerings so
                            scheduling has reliable resources to work with.
                        </p>
                    </div>
                    <Link
                        :href="resourceSetupUrl"
                        class="mt-6 inline-flex items-center gap-2 text-sm font-semibold text-[#285be8] transition group-hover:gap-3 dark:text-blue-400"
                    >
                        Open resources and catalog
                        <ArrowRight class="size-4" />
                    </Link>
                </article>
            </section>

            <section
                v-else
                class="rounded-2xl border border-dashed border-[#cfd8e7] bg-white p-8 text-center shadow-[0_8px_24px_rgba(37,53,80,0.04)] dark:border-slate-700 dark:bg-slate-900"
            >
                <div
                    class="mx-auto flex size-12 items-center justify-center rounded-full bg-[#e8eefc] text-[#285be8] dark:bg-blue-950 dark:text-blue-300"
                >
                    <Sparkles class="size-5" />
                </div>
                <h2
                    class="mt-4 text-xl font-semibold tracking-[-0.02em] text-[#26334a] dark:text-slate-100"
                >
                    Choose a workspace to get started
                </h2>
                <p
                    class="mx-auto mt-2 max-w-lg text-sm leading-6 text-slate-500 dark:text-slate-400"
                >
                    Select an existing institution or create a new one before
                    setting up its academic calendar and scheduling resources.
                </p>
                <Link
                    :href="organizations().url"
                    class="mt-6 inline-flex items-center gap-2 rounded-xl bg-[#285be8] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-[#1d49c5] focus-visible:ring-2 focus-visible:ring-[#285be8] focus-visible:ring-offset-2 focus-visible:outline-none dark:focus-visible:ring-offset-slate-900"
                >
                    View workspaces
                    <ArrowRight class="size-4" />
                </Link>
            </section>

            <section
                aria-labelledby="principles-heading"
                class="grid gap-4 rounded-2xl border border-[#dfe5ef] bg-[#fbfcfe] p-6 sm:grid-cols-[1fr_auto] sm:items-center sm:p-8 dark:border-slate-800 dark:bg-slate-900/70"
            >
                <div>
                    <p
                        class="font-mono text-[0.65rem] font-bold tracking-[0.22em] text-slate-400 uppercase"
                    >
                        A better starting point
                    </p>
                    <h2
                        id="principles-heading"
                        class="mt-2 text-lg font-semibold tracking-[-0.02em] text-[#26334a] dark:text-slate-100"
                    >
                        Clear inputs make calmer scheduling.
                    </h2>
                    <p
                        class="mt-2 max-w-2xl text-sm leading-6 text-slate-500 dark:text-slate-400"
                    >
                        TalaSched keeps setup decisions visible and reusable, so
                        your team can spend less time translating rules and more
                        time improving the timetable.
                    </p>
                </div>
                <div
                    class="flex size-14 items-center justify-center rounded-2xl bg-[#07101f] text-[#f2b544]"
                    aria-hidden="true"
                >
                    <Sparkles class="size-6" />
                </div>
            </section>
        </div>
    </div>
</template>
