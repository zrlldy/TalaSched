<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowDown,
    Filter,
    History,
    ShieldCheck,
    UserRound,
} from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import WorkspacePageHeader from '@/components/WorkspacePageHeader.vue';
import WorkspaceState from '@/components/WorkspaceState.vue';
import { dashboard } from '@/routes';
import { index } from '@/routes/audits';
import type { Organization } from '@/types';

type AuditEntry = {
    action: string;
    actor_name: string;
    subject_label: string | null;
    change_fields: string[];
    occurred_at: string;
};
type Props = {
    actions: string[];
    entries: AuditEntry[];
    filters: { action: string | null };
    nextCursor: string | null;
};

const props = defineProps<Props>();
const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as Organization | null,
);
const entryCountLabel = computed(
    () =>
        `${props.entries.length} recorded ${props.entries.length === 1 ? 'event' : 'events'}`,
);
const auditQuery = (cursor?: string): { action?: string; cursor?: string } => {
    const query: { action?: string; cursor?: string } = {};

    if (props.filters.action !== null) {
        query.action = props.filters.action;
    }

    if (cursor !== undefined) {
        query.cursor = cursor;
    }

    return query;
};
const nextPageUrl = computed(() => {
    if (organization.value === null || props.nextCursor === null) {
        return null;
    }

    return index(organization.value.slug, {
        query: auditQuery(props.nextCursor),
    }).url;
});
const applyActionFilter = (event: Event): void => {
    const action = (event.target as HTMLSelectElement).value;

    if (organization.value === null) {
        return;
    }

    router.get(
        index(organization.value.slug, {
            query: action === '' ? {} : { action },
        }).url,
        {},
        {
            preserveScroll: true,
            replace: true,
        },
    );
};
const actionLabel = (action: string): string =>
    action
        .replaceAll('.', ' ')
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
const fieldLabel = (field: string): string =>
    field
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
const formatTimestamp = (timestamp: string): string =>
    new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(timestamp));

defineOptions({
    layout: (layoutProps: { currentOrganization?: Organization | null }) => ({
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(layoutProps.currentOrganization?.slug ?? '')
                    .url,
            },
            {
                title: 'Audit ledger',
                href: index(layoutProps.currentOrganization?.slug ?? '').url,
            },
        ],
    }),
});
</script>

<template>
    <Head title="Audit ledger" />

    <div class="space-y-8">
        <WorkspacePageHeader
            section="Organization oversight"
            title="Audit ledger"
            description="Review an append-only record of consequential workspace changes without exposing the underlying snapshot values."
        >
            <template #metadata>
                <span>{{ organization?.name ?? 'Organization' }}</span>
                <span>{{ entryCountLabel }} in this view</span>
            </template>
            <template #actions>
                <Button variant="outline" as-child>
                    <Link :href="index(organization?.slug ?? '').url">
                        <History aria-hidden="true" />
                        Refresh ledger
                    </Link>
                </Button>
            </template>
        </WorkspacePageHeader>

        <section
            aria-labelledby="audit-filter-heading"
            class="flex flex-col gap-3 rounded-lg border bg-card p-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex items-center gap-3">
                <span
                    class="grid size-9 place-items-center rounded-md border border-schedule/25 bg-schedule/10 text-schedule"
                >
                    <Filter class="size-4" aria-hidden="true" />
                </span>
                <div>
                    <h2 id="audit-filter-heading" class="text-sm font-semibold">
                        Event filter
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Narrow the ledger to one recorded action.
                    </p>
                </div>
            </div>
            <label class="flex min-w-0 items-center gap-3 text-sm font-medium">
                <span class="sr-only">Filter audit events by action</span>
                <select
                    :value="filters.action ?? ''"
                    class="h-9 min-w-0 rounded-md border bg-background px-3 text-sm shadow-xs transition-colors outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 sm:min-w-64"
                    @change="applyActionFilter"
                >
                    <option value="">All recorded actions</option>
                    <option
                        v-for="action in actions"
                        :key="action"
                        :value="action"
                    >
                        {{ actionLabel(action) }}
                    </option>
                </select>
            </label>
        </section>

        <WorkspaceState
            v-if="entries.length === 0"
            variant="empty"
            title="No matching audit events"
            description="Recorded organization changes will appear here as actions are completed. Try clearing the filter to review the full ledger."
        />

        <section v-else aria-labelledby="audit-events-heading">
            <div class="mb-4 flex items-end justify-between gap-4">
                <div>
                    <p
                        class="font-mono text-[0.6875rem] font-semibold tracking-[0.18em] text-muted-foreground uppercase"
                    >
                        Append-only history
                    </p>
                    <h2
                        id="audit-events-heading"
                        class="mt-1 text-xl font-semibold tracking-[-0.02em]"
                    >
                        Recorded activity
                    </h2>
                </div>
                <ShieldCheck
                    class="size-5 text-schedule"
                    aria-label="Append-only audit history"
                />
            </div>

            <ol
                class="relative ml-2 border-l border-schedule/25 pl-6 sm:ml-3 sm:pl-8"
            >
                <li
                    v-for="entry in entries"
                    :key="`${entry.occurred_at}-${entry.action}-${entry.actor_name}`"
                    class="relative pb-6 last:pb-0"
                >
                    <span
                        aria-hidden="true"
                        class="absolute top-5 -left-[1.92rem] grid size-4 place-items-center rounded-full border-2 border-background bg-schedule sm:-left-[2.42rem]"
                    />
                    <article class="rounded-lg border bg-card p-4 sm:p-5">
                        <div
                            class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                        >
                            <div class="min-w-0">
                                <Badge
                                    variant="outline"
                                    class="border-schedule/25 bg-schedule/10 text-schedule"
                                >
                                    {{ actionLabel(entry.action) }}
                                </Badge>
                                <p class="mt-3 text-base font-semibold">
                                    {{
                                        entry.subject_label === null
                                            ? 'Organization-wide record'
                                            : entry.subject_label
                                    }}
                                </p>
                            </div>
                            <time
                                :datetime="entry.occurred_at"
                                class="shrink-0 font-mono text-xs text-muted-foreground"
                            >
                                {{ formatTimestamp(entry.occurred_at) }}
                            </time>
                        </div>

                        <div
                            class="mt-4 flex flex-col gap-3 border-t border-border pt-4 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <span
                                class="flex items-center gap-2 text-sm text-muted-foreground"
                            >
                                <UserRound class="size-4" aria-hidden="true" />
                                {{ entry.actor_name }}
                            </span>
                            <div
                                v-if="entry.change_fields.length > 0"
                                class="flex flex-wrap items-center gap-1.5"
                            >
                                <span
                                    class="font-mono text-[0.6875rem] text-muted-foreground uppercase"
                                >
                                    Recorded fields
                                </span>
                                <span
                                    v-for="field in entry.change_fields"
                                    :key="field"
                                    class="rounded-sm bg-muted px-2 py-1 font-mono text-xs text-muted-foreground"
                                >
                                    {{ fieldLabel(field) }}
                                </span>
                            </div>
                        </div>
                    </article>
                </li>
            </ol>

            <div v-if="nextPageUrl !== null" class="mt-6 flex justify-center">
                <Button variant="outline" as-child>
                    <Link :href="nextPageUrl">
                        <ArrowDown aria-hidden="true" />
                        Load earlier records
                    </Link>
                </Button>
            </div>
        </section>
    </div>
</template>
