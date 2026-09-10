<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Check,
    ClipboardCheck,
    CircleAlert,
    Clock3,
    History,
    RefreshCw,
    Search,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ApprovalNavigation from '@/components/ApprovalNavigation.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import ValidationSummary from '@/components/ValidationSummary.vue';
import WorkspacePageHeader from '@/components/WorkspacePageHeader.vue';
import { decide, inbox } from '@/routes/approvals';
import { show as showTimetable } from '@/routes/scheduling/timetables';
import type { Organization } from '@/types';

type Action = {
    actor_name: string;
    decision: string;
    comment: string | null;
    signatory_name: string | null;
    signatory_position: string | null;
    signatory_academic_unit: string | null;
    has_signature: boolean;
    acted_at: string | null;
};
type Step = {
    sequence: number;
    label: string;
    status: string;
    selector_type: string | null;
    required_permission: string | null;
    minimum_approvals: number;
    allow_self_approval: boolean;
    signatory_slot: string | null;
    academic_unit_id: string | null;
    academic_unit_name: string | null;
    can_decide: boolean;
    actions: Action[];
};
type Instance = {
    id: string;
    timetable_id: string;
    timetable_name: string;
    version_number: number;
    version_status: string;
    status: string;
    workflow_id: string;
    workflow_name: string;
    workflow_version: number;
    submitter_name: string;
    submitted_at: string | null;
    updated_at: string | null;
    can_decide: boolean;
    steps: Step[];
};
type Props = { instances: Instance[]; canManageApprovals: boolean };

const props = defineProps<Props>();
const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as Organization | null,
);
const selectedId = ref<string | null>(
    props.instances.find((instance) => instance.status === 'pending')?.id ??
        null,
);
const filter = ref<'pending' | 'history'>('pending');
const search = ref('');
const idempotencyKey = ref(crypto.randomUUID());
const pendingCount = computed(
    () =>
        props.instances.filter((instance) => instance.status === 'pending')
            .length,
);
const filteredInstances = computed(() =>
    props.instances.filter((instance) =>
        filter.value === 'pending'
            ? instance.status === 'pending'
            : instance.status !== 'pending',
    ),
);
const visibleInstances = computed(() => {
    const query = search.value.trim().toLowerCase();

    return filteredInstances.value.filter((instance) =>
        [
            instance.timetable_name,
            instance.workflow_name,
            instance.submitter_name,
        ].some((value) => value.toLowerCase().includes(query)),
    );
});
const selectedInstance = computed(
    () =>
        visibleInstances.value.find(
            (instance) => instance.id === selectedId.value,
        ) ??
        visibleInstances.value[0] ??
        null,
);
watch(
    () => selectedInstance.value?.id,
    () => {
        idempotencyKey.value = crypto.randomUUID();
    },
);
const statusLabel = (status: string): string =>
    status
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
const statusClass = (status: string): string => {
    if (status === 'approved') {
        return 'border-available/30 bg-available/10 text-available';
    }

    if (status === 'rejected' || status === 'changes_requested') {
        return 'border-conflict/30 bg-conflict/10 text-foreground';
    }

    if (status === 'active' || status === 'pending') {
        return 'border-warning/35 bg-warning/15 text-foreground';
    }

    return 'border-border bg-muted text-muted-foreground';
};
const stepClass = (status: string): string => {
    if (status === 'approved') {
        return 'border-available/30 bg-available/10 text-available';
    }

    if (status === 'active') {
        return 'border-warning bg-warning/15 text-foreground';
    }

    if (status === 'rejected' || status === 'changes_requested') {
        return 'border-conflict bg-conflict/10 text-foreground';
    }

    return 'border-border bg-muted text-muted-foreground';
};
const formatDate = (value: string | null): string =>
    value === null
        ? 'Not recorded'
        : new Intl.DateTimeFormat(undefined, {
              month: 'short',
              day: 'numeric',
              year: 'numeric',
              hour: 'numeric',
              minute: '2-digit',
              timeZone: page.props.organizationTimezone ?? undefined,
          }).format(new Date(value));
const choose = (instance: Instance): void => {
    selectedId.value = instance.id;
};

defineOptions({
    layout: (layoutProps: { currentOrganization?: Organization | null }) => ({
        breadcrumbs: [
            {
                title: 'Approvals',
                href: inbox(layoutProps.currentOrganization?.slug ?? '').url,
            },
            {
                title: 'Inbox',
                href: inbox(layoutProps.currentOrganization?.slug ?? '').url,
            },
        ],
    }),
});
</script>

<template>
    <div class="mx-auto w-full max-w-7xl min-w-0 space-y-5 p-4 sm:p-6">
        <Head title="Approval inbox" />
        <WorkspacePageHeader
            section="Review & publish"
            title="Approval inbox"
            description="Review submitted timetables and keep each decision with its version."
        >
            <template #actions>
                <Button variant="ghost" size="icon" as-child>
                    <Link
                        :href="inbox(organization?.slug ?? '').url"
                        aria-label="Refresh inbox"
                        ><RefreshCw class="size-4"
                    /></Link>
                </Button>
            </template>
        </WorkspacePageHeader>
        <ApprovalNavigation
            :organization-slug="organization?.slug ?? ''"
            current="inbox"
            :can-manage="canManageApprovals"
        />

        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <div
                class="flex gap-1 self-start rounded-lg border bg-muted/50 p-1"
                role="group"
                aria-label="Approval filters"
            >
                <Button
                    :variant="filter === 'pending' ? 'secondary' : 'ghost'"
                    size="sm"
                    :aria-pressed="filter === 'pending'"
                    @click="filter = 'pending'"
                >
                    <Clock3 /> Pending
                    <span
                        class="rounded-sm bg-background px-1.5 font-schedule text-xs tabular-nums"
                        >{{ pendingCount }}</span
                    >
                </Button>
                <Button
                    :variant="filter === 'history' ? 'secondary' : 'ghost'"
                    size="sm"
                    :aria-pressed="filter === 'history'"
                    @click="filter = 'history'"
                >
                    <History /> History
                    <span
                        class="rounded-sm bg-background px-1.5 font-schedule text-xs tabular-nums"
                        >{{ instances.length - pendingCount }}</span
                    >
                </Button>
            </div>
            <div class="relative w-full sm:max-w-72">
                <Search
                    class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                />
                <Input
                    v-model="search"
                    type="search"
                    aria-label="Search approval requests"
                    placeholder="Find a timetable or submitter..."
                    class="pl-9"
                />
            </div>
        </div>

        <div
            class="grid min-w-0 overflow-hidden rounded-lg border bg-card lg:grid-cols-[minmax(16rem,0.8fr)_minmax(0,1.5fr)]"
        >
            <section
                class="min-w-0 border-b bg-muted/20 lg:border-r lg:border-b-0"
                aria-label="Approval requests"
            >
                <div
                    class="flex items-center justify-between border-b px-4 py-3"
                >
                    <h2 class="text-xs font-medium text-muted-foreground">
                        {{
                            filter === 'pending'
                                ? 'Submitted for review'
                                : 'Completed reviews'
                        }}
                    </h2>
                    <span
                        class="font-schedule text-xs text-muted-foreground"
                        aria-live="polite"
                        >{{ visibleInstances.length }} requests</span
                    >
                </div>
                <div
                    v-if="visibleInstances.length"
                    class="max-h-80 overflow-y-auto lg:max-h-[65vh]"
                >
                    <button
                        v-for="instance in visibleInstances"
                        :key="instance.id"
                        type="button"
                        class="relative flex w-full flex-col gap-3 border-b px-4 py-4 text-left transition-colors last:border-b-0 hover:bg-muted/70 focus-visible:z-10 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                        :class="
                            selectedInstance?.id === instance.id
                                ? 'bg-accent/60'
                                : ''
                        "
                        :aria-pressed="selectedInstance?.id === instance.id"
                        @click="choose(instance)"
                    >
                        <span
                            v-if="selectedInstance?.id === instance.id"
                            class="absolute inset-y-0 left-0 w-0.5 bg-schedule"
                            aria-hidden="true"
                        />
                        <span class="flex items-center justify-between gap-2">
                            <span
                                class="font-schedule text-xs text-muted-foreground"
                                >VERSION {{ instance.version_number }}</span
                            >
                            <Badge
                                variant="outline"
                                :class="statusClass(instance.status)"
                                >{{
                                    instance.can_decide
                                        ? 'Your review'
                                        : statusLabel(instance.status)
                                }}</Badge
                            >
                        </span>
                        <span class="min-w-0">
                            <span class="block font-semibold break-words">{{
                                instance.timetable_name
                            }}</span>
                            <span
                                class="mt-1 block text-xs text-muted-foreground"
                                >{{ instance.workflow_name }}</span
                            >
                        </span>
                        <span
                            class="flex flex-wrap items-center justify-between gap-1 text-xs text-muted-foreground"
                        >
                            <span>{{ instance.submitter_name }}</span>
                            <span>{{ formatDate(instance.submitted_at) }}</span>
                        </span>
                    </button>
                </div>
                <div v-else class="px-4 py-8 text-sm text-muted-foreground">
                    {{
                        search
                            ? 'No requests match your search.'
                            : filter === 'pending'
                              ? 'No pending requests.'
                              : 'No completed reviews.'
                    }}
                    <Button
                        v-if="search"
                        variant="link"
                        class="mt-2 block h-auto p-0"
                        @click="search = ''"
                        >Clear search</Button
                    >
                </div>
                <div
                    class="flex items-start gap-2 border-t px-4 py-3 text-xs leading-5 text-muted-foreground"
                >
                    <ClipboardCheck class="mt-0.5 size-4 shrink-0" />
                    <p>
                        Approval records stay with the submitted version.
                        Publishing is a separate step.
                    </p>
                </div>
            </section>

            <section
                v-if="selectedInstance"
                aria-label="Selected approval request"
                class="min-w-0 space-y-6 p-5 text-card-foreground sm:p-6"
            >
                <div
                    class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start"
                >
                    <div>
                        <p
                            class="font-schedule text-xs font-semibold tracking-[0.16em] text-muted-foreground uppercase"
                        >
                            Review request
                        </p>
                        <h2
                            class="mt-2 text-xl font-semibold tracking-tight break-words"
                        >
                            {{ selectedInstance.timetable_name }}
                        </h2>
                        <p class="mt-2 text-sm text-muted-foreground">
                            Version {{ selectedInstance.version_number }} ·
                            {{ selectedInstance.workflow_name }} v{{
                                selectedInstance.workflow_version
                            }}
                        </p>
                    </div>
                    <Badge
                        variant="outline"
                        :class="statusClass(selectedInstance.status)"
                        >{{ statusLabel(selectedInstance.status) }}</Badge
                    >
                </div>
                <Button variant="outline" size="sm" as-child>
                    <Link
                        :href="
                            showTimetable(
                                [
                                    organization?.slug ?? '',
                                    selectedInstance.timetable_id,
                                ],
                                { query: { version_id: selectedInstance.id } },
                            ).url
                        "
                        >Open timetable <ArrowRight
                    /></Link>
                </Button>
                <div
                    class="grid gap-3 rounded-md border bg-muted/30 p-4 text-sm sm:grid-cols-3"
                >
                    <div>
                        <p class="text-xs text-muted-foreground">
                            Submitted by
                        </p>
                        <p class="mt-1 font-medium">
                            {{ selectedInstance.submitter_name }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Submitted</p>
                        <p class="mt-1 font-medium">
                            {{ formatDate(selectedInstance.submitted_at) }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">
                            Version state
                        </p>
                        <p class="mt-1 font-medium">
                            {{ statusLabel(selectedInstance.version_status) }}
                        </p>
                    </div>
                </div>

                <div>
                    <h3
                        class="font-schedule text-xs font-semibold tracking-[0.16em] text-muted-foreground uppercase"
                    >
                        Approval progress
                    </h3>
                    <ol class="mt-4 space-y-0">
                        <li
                            v-for="(step, index) in selectedInstance.steps"
                            :key="step.sequence"
                            class="relative flex gap-4 pb-6 last:pb-0"
                        >
                            <div
                                v-if="index < selectedInstance.steps.length - 1"
                                class="absolute top-8 left-3.5 h-full w-px bg-border"
                                aria-hidden="true"
                            />
                            <div
                                class="relative z-10 grid size-7 shrink-0 place-items-center rounded-full border text-xs font-semibold"
                                :class="stepClass(step.status)"
                            >
                                <Check
                                    v-if="step.status === 'approved'"
                                    class="size-4"
                                /><span v-else>{{ step.sequence }}</span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div
                                    class="flex flex-wrap items-center justify-between gap-2"
                                >
                                    <div>
                                        <p class="font-medium">
                                            {{ step.label }}
                                        </p>
                                        <p
                                            class="mt-1 text-xs text-muted-foreground"
                                        >
                                            {{
                                                step.academic_unit_name ??
                                                'Organization-wide'
                                            }}
                                            ·
                                            {{ step.minimum_approvals }}
                                            approval{{
                                                step.minimum_approvals === 1
                                                    ? ''
                                                    : 's'
                                            }}
                                        </p>
                                    </div>
                                    <Badge
                                        variant="outline"
                                        :class="statusClass(step.status)"
                                        >{{ statusLabel(step.status) }}</Badge
                                    >
                                </div>
                                <div
                                    v-if="step.actions.length"
                                    class="mt-3 space-y-2"
                                >
                                    <div
                                        v-for="action in step.actions"
                                        :key="
                                            action.actor_name +
                                            '-' +
                                            action.acted_at
                                        "
                                        class="rounded-md border bg-background p-3 text-sm"
                                    >
                                        <div
                                            class="flex flex-wrap items-center justify-between gap-2"
                                        >
                                            <span class="font-medium">{{
                                                action.signatory_name ??
                                                action.actor_name
                                            }}</span
                                            ><span
                                                class="text-xs text-muted-foreground"
                                                >{{
                                                    formatDate(action.acted_at)
                                                }}</span
                                            >
                                        </div>
                                        <p
                                            class="mt-1 text-xs text-muted-foreground"
                                        >
                                            {{ statusLabel(action.decision)
                                            }}<span
                                                v-if="action.signatory_position"
                                            >
                                                ·
                                                {{
                                                    action.signatory_position
                                                }}</span
                                            ><span v-if="action.has_signature">
                                                · signature captured</span
                                            >
                                        </p>
                                        <p
                                            v-if="action.comment"
                                            class="mt-2 text-sm text-muted-foreground"
                                        >
                                            {{ action.comment }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </li>
                    </ol>
                </div>

                <div
                    v-if="selectedInstance.can_decide"
                    class="border-t border-border pt-6"
                >
                    <div class="flex items-start gap-3">
                        <div
                            class="grid size-9 shrink-0 place-items-center rounded-md border border-warning/30 bg-warning/15 text-foreground"
                        >
                            <CircleAlert class="size-4" />
                        </div>
                        <div>
                            <h3 class="font-semibold">Record your decision</h3>
                            <p
                                class="mt-1 text-sm leading-6 text-muted-foreground"
                            >
                                Choose a decision and add context for the next
                                reviewer or timetable editor.
                            </p>
                        </div>
                    </div>
                    <Form
                        :key="selectedInstance.id"
                        v-bind="
                            decide.form([
                                organization?.slug ?? '',
                                selectedInstance.id,
                            ])
                        "
                        class="mt-5 space-y-4"
                        v-slot="{ errors, processing }"
                    >
                        <ValidationSummary
                            :errors="errors"
                            title="Your decision could not be saved"
                        />
                        <input
                            type="hidden"
                            name="idempotency_key"
                            :value="idempotencyKey"
                        />
                        <div>
                            <label
                                for="approval-comment"
                                class="text-sm font-medium"
                                >Comment
                                <span class="font-normal text-muted-foreground"
                                    >(optional)</span
                                ></label
                            >
                            <textarea
                                id="approval-comment"
                                name="comment"
                                class="mt-2 min-h-24 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                placeholder="Explain the decision or identify the changes needed."
                            />
                            <p
                                v-if="errors.comment"
                                class="mt-1 text-sm text-destructive"
                            >
                                {{ errors.comment }}
                            </p>
                            <p
                                v-if="errors.decision"
                                class="mt-1 text-sm text-destructive"
                            >
                                {{ errors.decision }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                type="submit"
                                name="decision"
                                value="approve"
                                :disabled="processing"
                                ><Check /> Approve</Button
                            ><Button
                                type="submit"
                                name="decision"
                                value="request_changes"
                                variant="outline"
                                :disabled="processing"
                                ><ArrowRight /> Request changes</Button
                            ><Button
                                type="submit"
                                name="decision"
                                value="reject"
                                variant="destructive"
                                :disabled="processing"
                                ><X /> Reject</Button
                            >
                        </div>
                    </Form>
                </div>
                <div
                    v-else-if="selectedInstance.status === 'pending'"
                    class="rounded-md border border-border bg-muted/30 p-4 text-sm text-muted-foreground"
                >
                    This request is visible for context, but the active step is
                    assigned to another eligible approver.
                </div>
            </section>
            <section
                v-else
                class="flex min-h-80 flex-col items-center justify-center gap-4 p-6 text-center sm:p-10"
                aria-label="Approval review"
            >
                <div
                    class="grid size-14 place-items-center rounded-xl border border-available/25 bg-available/10 text-available"
                >
                    <ClipboardCheck class="size-7" />
                </div>
                <div>
                    <p class="font-schedule text-xs text-muted-foreground">
                        {{
                            search
                                ? 'SEARCH RESULTS'
                                : filter === 'pending'
                                  ? 'REVIEW QUEUE CLEAR'
                                  : 'DECISION HISTORY'
                        }}
                    </p>
                    <h2 class="mt-2 text-xl font-semibold tracking-tight">
                        {{
                            search
                                ? 'No matching requests'
                                : filter === 'pending'
                                  ? 'Nothing is waiting on you'
                                  : 'No approval history yet'
                        }}
                    </h2>
                    <p
                        class="mt-2 max-w-md text-sm leading-6 text-muted-foreground"
                    >
                        {{
                            search
                                ? 'Try a timetable name, workflow, or submitter.'
                                : filter === 'pending'
                                  ? 'Submitted timetables will appear here when they are ready for review.'
                                  : 'Completed reviews will appear here with their decisions and comments.'
                        }}
                    </p>
                </div>
                <Button v-if="search" variant="outline" @click="search = ''"
                    >Clear search</Button
                >
                <Button
                    v-else-if="filter === 'history'"
                    variant="outline"
                    @click="filter = 'pending'"
                    >View pending requests</Button
                >
            </section>
        </div>
    </div>
</template>
