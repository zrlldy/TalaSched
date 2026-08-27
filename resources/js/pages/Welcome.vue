<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    CalendarClock,
    Check,
    Command,
    Layers3,
    Sparkles,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { dashboard, login, register } from '@/routes';
import { index as organizations } from '@/routes/organizations';

const page = usePage();
const dashboardUrl = computed(() =>
    page.props.currentOrganization
        ? dashboard(page.props.currentOrganization.slug).url
        : organizations().url,
);
</script>

<template>
    <Head title="TalaSched" />

    <div class="min-h-screen bg-background text-foreground">
        <header
            class="mx-auto flex w-full max-w-6xl items-center justify-between border-b border-border px-5 py-4 sm:px-8"
        >
            <Link
                :href="page.props.auth.user ? dashboardUrl : '/'"
                class="flex items-center gap-2.5 font-semibold tracking-tight"
            >
                <span
                    class="flex size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground"
                >
                    <AppLogoIcon class="size-5 fill-current" />
                </span>
                <span>TalaSched</span>
            </Link>

            <nav class="flex items-center gap-2 text-sm" aria-label="Account">
                <Link
                    v-if="page.props.auth.user"
                    :href="dashboardUrl"
                    class="rounded-md px-3 py-2 font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                >
                    Open workspace
                </Link>
                <template v-else>
                    <Link
                        :href="login()"
                        class="rounded-md px-3 py-2 font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    >
                        Log in
                    </Link>
                    <Link
                        :href="register()"
                        class="rounded-md bg-primary px-3 py-2 font-medium text-primary-foreground transition-colors hover:bg-primary/90"
                    >
                        Create workspace
                    </Link>
                </template>
            </nav>
        </header>

        <main
            class="mx-auto grid w-full max-w-6xl gap-12 px-5 py-12 sm:px-8 lg:grid-cols-[minmax(0,0.95fr)_minmax(28rem,1.05fr)] lg:items-center lg:gap-16 lg:py-20"
        >
            <section>
                <div
                    class="mb-5 inline-flex items-center gap-2 rounded-md border border-schedule/25 bg-schedule/10 px-2.5 py-1.5 font-schedule text-[0.6875rem] font-semibold tracking-[0.16em] text-schedule uppercase"
                >
                    <span class="size-1.5 rounded-full bg-available" />
                    Scheduling, with a clear signal
                </div>
                <h1
                    class="max-w-2xl text-4xl leading-[1.05] font-semibold tracking-[-0.04em] text-balance sm:text-5xl lg:text-6xl"
                >
                    Make the week legible.
                </h1>
                <p
                    class="mt-5 max-w-xl text-base leading-7 text-muted-foreground sm:text-lg"
                >
                    TalaSched gives academic teams one calm workspace to shape
                    the academic structure, place the resources, and resolve
                    timetable conflicts before they become surprises.
                </p>
                <div class="mt-7 flex flex-wrap items-center gap-3">
                    <Link
                        :href="page.props.auth.user ? dashboardUrl : register()"
                        class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground transition-colors hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                    >
                        Start planning
                        <ArrowRight class="size-4" />
                    </Link>
                    <span class="font-schedule text-xs text-muted-foreground">
                        Keyboard-ready by design
                    </span>
                </div>

                <div
                    class="mt-10 grid max-w-xl gap-3 border-t border-border pt-5 sm:grid-cols-3"
                >
                    <div>
                        <p
                            class="font-schedule text-[0.6875rem] font-semibold tracking-[0.14em] text-schedule uppercase"
                        >
                            01
                        </p>
                        <p class="mt-2 text-sm font-medium">
                            Define the rhythm
                        </p>
                    </div>
                    <div>
                        <p
                            class="font-schedule text-[0.6875rem] font-semibold tracking-[0.14em] text-available uppercase"
                        >
                            02
                        </p>
                        <p class="mt-2 text-sm font-medium">
                            Place what matters
                        </p>
                    </div>
                    <div>
                        <p
                            class="font-schedule text-[0.6875rem] font-semibold tracking-[0.14em] text-warning uppercase"
                        >
                            03
                        </p>
                        <p class="mt-2 text-sm font-medium">
                            Resolve the signal
                        </p>
                    </div>
                </div>
            </section>

            <section aria-label="TalaSched workspace preview" class="relative">
                <div
                    class="absolute -inset-5 -z-10 bg-[radial-gradient(circle_at_70%_20%,color-mix(in_srgb,var(--schedule)_18%,transparent),transparent_45%),radial-gradient(circle_at_20%_80%,color-mix(in_srgb,var(--available)_12%,transparent),transparent_40%)]"
                />
                <div
                    class="overflow-hidden rounded-lg border border-border bg-card"
                >
                    <div
                        class="flex items-center justify-between border-b border-border px-4 py-3"
                    >
                        <div class="flex items-center gap-2">
                            <CalendarClock class="size-4 text-schedule" />
                            <span class="text-sm font-semibold"
                                >Engineering · Week 14</span
                            >
                        </div>
                        <div
                            class="flex items-center gap-1.5 font-schedule text-[0.6875rem] text-muted-foreground"
                        >
                            <Command class="size-3" /> K
                        </div>
                    </div>

                    <div
                        class="grid grid-cols-[5rem_repeat(5,minmax(0,1fr))] text-[0.6875rem]"
                    >
                        <div
                            class="border-r border-b border-border bg-muted/45 p-3 text-muted-foreground"
                        >
                            GMT+8
                        </div>
                        <div
                            v-for="day in ['Mon', 'Tue', 'Wed', 'Thu', 'Fri']"
                            :key="day"
                            class="border-b border-border p-3 text-center font-semibold"
                        >
                            {{ day }}
                        </div>

                        <template
                            v-for="time in ['08:00', '10:00', '12:00', '14:00']"
                            :key="time"
                        >
                            <div
                                class="border-r border-border bg-muted/20 px-3 py-4 font-schedule text-muted-foreground"
                            >
                                {{ time }}
                            </div>
                            <div
                                v-for="day in 5"
                                :key="`${time}-${day}`"
                                class="min-h-20 border-b border-border p-1.5"
                            >
                                <div
                                    v-if="time === '08:00' && day === 1"
                                    class="h-full rounded-md border border-schedule/30 bg-schedule/10 p-2 text-schedule"
                                >
                                    <p class="font-semibold">CS 201</p>
                                    <p class="mt-1 opacity-75">
                                        A. Santos · B204
                                    </p>
                                </div>
                                <div
                                    v-else-if="time === '10:00' && day === 3"
                                    class="h-full rounded-md border border-conflict/40 bg-conflict/10 p-2 text-conflict"
                                >
                                    <p
                                        class="flex items-center gap-1 font-semibold"
                                    >
                                        <span
                                            class="size-1.5 rounded-full bg-conflict"
                                        />
                                        Conflict
                                    </p>
                                    <p class="mt-1 opacity-75">
                                        2 overlapping sessions
                                    </p>
                                </div>
                                <div
                                    v-else-if="time === '14:00' && day === 5"
                                    class="h-full rounded-md border border-available/30 bg-available/10 p-2 text-available"
                                >
                                    <p class="font-semibold">Open block</p>
                                    <p class="mt-1 opacity-75">
                                        4 rooms available
                                    </p>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div
                        class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-border bg-muted/25 px-4 py-3 font-schedule text-[0.6875rem] text-muted-foreground"
                    >
                        <span class="inline-flex items-center gap-1.5"
                            ><span class="size-1.5 rounded-full bg-schedule" />
                            Scheduled</span
                        >
                        <span class="inline-flex items-center gap-1.5"
                            ><span class="size-1.5 rounded-full bg-conflict" />
                            Needs attention</span
                        >
                        <span class="inline-flex items-center gap-1.5"
                            ><span class="size-1.5 rounded-full bg-available" />
                            Available</span
                        >
                    </div>
                </div>

                <div
                    class="absolute -bottom-5 -left-5 hidden w-56 rounded-lg border border-border bg-popover p-3 text-popover-foreground sm:block"
                >
                    <div class="flex items-start gap-2">
                        <div
                            class="mt-0.5 rounded-md bg-available/10 p-1.5 text-available"
                        >
                            <Check class="size-3.5" />
                        </div>
                        <div>
                            <p class="text-xs font-semibold">
                                Signal is visible
                            </p>
                            <p
                                class="mt-1 text-[0.6875rem] leading-4 text-muted-foreground"
                            >
                                Inspect conflicts without leaving the board.
                            </p>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <footer
            class="mx-auto flex w-full max-w-6xl flex-wrap items-center gap-x-6 gap-y-2 border-t border-border px-5 py-5 font-schedule text-[0.6875rem] text-muted-foreground sm:px-8"
        >
            <span class="inline-flex items-center gap-2"
                ><Sparkles class="size-3.5 text-warning" /> Built for academic
                operations</span
            >
            <span class="inline-flex items-center gap-2"
                ><Layers3 class="size-3.5 text-schedule" /> Structure stays
                reusable</span
            >
        </footer>
    </div>
</template>
