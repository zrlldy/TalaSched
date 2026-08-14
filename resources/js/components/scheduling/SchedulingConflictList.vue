<script setup lang="ts">
import { CircleAlert, CircleX, TriangleAlert } from '@lucide/vue';
import { computed, useId } from 'vue';
import type { ScheduleIssue } from '@/types';

type Props = {
    issues: ScheduleIssue[];
    title?: string;
    description?: string;
};

type IssueCategory =
    'time' | 'resource' | 'requirement' | 'workload' | 'general';

const props = defineProps<Props>();

const issueCategories: Array<{ key: IssueCategory; label: string }> = [
    { key: 'time', label: 'Time' },
    { key: 'resource', label: 'Resources' },
    { key: 'requirement', label: 'Requirements' },
    { key: 'workload', label: 'Workload' },
    { key: 'general', label: 'Other checks' },
];

const issueCategoryByCode: Partial<Record<string, IssueCategory>> = {
    invalid_granularity: 'time',
    resource_overlap: 'time',
    resource_role_mismatch: 'resource',
    resource_unavailable: 'resource',
    offering_group_mismatch: 'requirement',
    room_capacity: 'requirement',
    room_type_required: 'requirement',
    faculty_daily_load: 'workload',
    faculty_weekly_load: 'workload',
};

const hardIssueCount = computed(
    () => props.issues.filter((issue) => issue.severity === 'hard').length,
);
const softIssueCount = computed(
    () => props.issues.filter((issue) => issue.severity === 'soft').length,
);
const hasHardIssues = computed(() => hardIssueCount.value > 0);
const groupedIssues = computed(() =>
    issueCategories
        .map((category) => ({
            ...category,
            issues: props.issues.filter(
                (issue) =>
                    (issueCategoryByCode[issue.code] ?? 'general') ===
                    category.key,
            ),
        }))
        .filter((category) => category.issues.length > 0),
);

const defaultTitle = computed(() => {
    if (hasHardIssues.value) {
        return `${hardIssueCount.value} blocking ${hardIssueCount.value === 1 ? 'conflict' : 'conflicts'}`;
    }

    return `${softIssueCount.value} ${softIssueCount.value === 1 ? 'warning' : 'warnings'} to review`;
});
const defaultDescription = computed(() =>
    hasHardIssues.value
        ? 'Resolve each blocking conflict before saving this schedule entry.'
        : 'Review these scheduling warnings before you continue.',
);

const headingId = useId();
const descriptionId = useId();
</script>

<template>
    <section
        v-if="issues.length > 0"
        data-slot="scheduling-conflict-list"
        :role="hasHardIssues ? 'alert' : 'status'"
        :aria-live="hasHardIssues ? 'assertive' : 'polite'"
        :aria-labelledby="headingId"
        :aria-describedby="descriptionId"
        class="overflow-hidden rounded-lg border bg-card text-card-foreground"
        :class="hasHardIssues ? 'border-conflict/35' : 'border-warning/45'"
    >
        <header
            class="flex items-start gap-3 border-b px-4 py-4 sm:px-5"
            :class="hasHardIssues ? 'bg-conflict/8' : 'bg-warning/10'"
        >
            <div
                aria-hidden="true"
                class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-md border text-foreground"
                :class="
                    hasHardIssues
                        ? 'border-conflict/30 bg-conflict/12'
                        : 'border-warning/35 bg-warning/15'
                "
            >
                <CircleAlert class="size-4" />
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h2
                        :id="headingId"
                        class="text-sm font-semibold text-foreground"
                    >
                        {{ title ?? defaultTitle }}
                    </h2>
                    <span
                        class="rounded-sm px-1.5 py-0.5 font-schedule text-[0.625rem] font-semibold tracking-[0.12em] uppercase"
                        :class="
                            hasHardIssues
                                ? 'bg-conflict text-conflict-foreground'
                                : 'bg-warning text-warning-foreground'
                        "
                    >
                        {{ hasHardIssues ? 'Save blocked' : 'Review' }}
                    </span>
                </div>
                <p
                    :id="descriptionId"
                    class="mt-1 text-sm leading-5 text-muted-foreground"
                >
                    {{ description ?? defaultDescription }}
                </p>
            </div>
        </header>

        <div class="divide-y divide-border">
            <section
                v-for="group in groupedIssues"
                :key="group.key"
                class="grid gap-3 px-4 py-4 sm:grid-cols-[8rem_minmax(0,1fr)] sm:px-5"
            >
                <h3
                    class="font-schedule text-[0.6875rem] font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                >
                    {{ group.label }} · {{ group.issues.length }}
                </h3>

                <ul class="grid gap-3" role="list">
                    <li
                        v-for="(issue, index) in group.issues"
                        :key="`${issue.code}-${issue.resource?.id ?? issue.field}-${index}`"
                        class="grid grid-cols-[auto_minmax(0,1fr)] gap-3"
                    >
                        <div
                            aria-hidden="true"
                            class="mt-0.5 flex size-6 items-center justify-center rounded-sm"
                            :class="
                                issue.severity === 'hard'
                                    ? 'bg-conflict/12 text-foreground'
                                    : 'bg-warning/15 text-foreground'
                            "
                        >
                            <CircleX
                                v-if="issue.severity === 'hard'"
                                class="size-4"
                            />
                            <TriangleAlert v-else class="size-4" />
                        </div>

                        <div class="min-w-0">
                            <div
                                class="flex flex-wrap items-baseline gap-x-2 gap-y-1"
                            >
                                <p class="text-sm font-medium text-foreground">
                                    {{ issue.message }}
                                </p>
                                <span
                                    class="font-schedule text-[0.625rem] font-semibold tracking-[0.1em] text-muted-foreground uppercase"
                                >
                                    {{
                                        issue.severity === 'hard'
                                            ? 'Blocking'
                                            : 'Warning'
                                    }}
                                </span>
                            </div>

                            <dl
                                class="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 font-schedule text-[0.6875rem] leading-5 text-muted-foreground"
                            >
                                <div
                                    v-if="issue.resource?.name"
                                    class="flex gap-1"
                                >
                                    <dt>Resource</dt>
                                    <dd class="font-medium text-foreground">
                                        {{ issue.resource.name }}
                                    </dd>
                                </div>
                                <div class="flex gap-1">
                                    <dt>Rule</dt>
                                    <dd class="break-all text-foreground">
                                        {{ issue.rule_code }}
                                    </dd>
                                </div>
                                <div class="flex gap-1">
                                    <dt>Field</dt>
                                    <dd class="break-all text-foreground">
                                        {{ issue.field }}
                                    </dd>
                                </div>
                                <div
                                    v-if="issue.conflicting_entry_id"
                                    class="flex min-w-0 gap-1"
                                >
                                    <dt>Entry</dt>
                                    <dd class="break-all text-foreground">
                                        {{ issue.conflicting_entry_id }}
                                    </dd>
                                </div>
                            </dl>

                            <div v-if="$slots['issue-action']" class="mt-2">
                                <slot name="issue-action" :issue="issue" />
                            </div>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </section>
</template>
