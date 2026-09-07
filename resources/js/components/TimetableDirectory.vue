<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, CalendarDays } from '@lucide/vue';
import { show } from '@/routes/scheduling/timetables';
import type { TimetableSummary } from '@/types/timetable-directory';

defineProps<{ timetables: TimetableSummary[]; organizationSlug: string }>();
const label = (status: string) =>
    ({
        draft: 'Draft',
        in_review: 'In review',
        changes_requested: 'Changes requested',
        approved: 'Approved',
        published: 'Published',
        superseded: 'Superseded',
    })[status] ?? status;
</script>

<template>
    <ul
        class="divide-y divide-border overflow-hidden rounded-lg border bg-card"
    >
        <li v-for="timetable in timetables" :key="timetable.id">
            <Link
                :href="show([organizationSlug, timetable.id])"
                class="group flex min-h-24 items-center gap-4 p-4 transition-colors hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:px-5"
            >
                <CalendarDays
                    class="hidden size-5 shrink-0 text-muted-foreground sm:block"
                />
                <div class="min-w-0 flex-1">
                    <p class="font-medium break-words">{{ timetable.name }}</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ timetable.period }} · {{ timetable.year }}
                    </p>
                    <p
                        v-if="timetable.version"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        Version {{ timetable.version.number }} ·
                        {{ timetable.version.entries }} scheduled classes
                    </p>
                </div>
                <span
                    class="shrink-0 rounded-md border px-2 py-1 text-xs font-medium"
                    :class="
                        timetable.version?.status === 'published'
                            ? 'border-available/30 bg-available/10 text-available'
                            : 'bg-muted text-muted-foreground'
                    "
                >
                    {{
                        timetable.version
                            ? label(timetable.version.status)
                            : 'No version'
                    }}
                </span>
                <ArrowRight
                    class="hidden size-4 shrink-0 text-muted-foreground group-hover:text-primary sm:block"
                />
            </Link>
        </li>
    </ul>
</template>
