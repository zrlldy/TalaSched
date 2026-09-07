<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Check,
    CircleAlert,
    Clock3,
    History,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import WorkspacePageHeader from '@/components/WorkspacePageHeader.vue';
import WorkspaceState from '@/components/WorkspaceState.vue';
import { decide, inbox, signatories, workflows } from '@/routes/approvals';
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
const idempotencyKey = ref(crypto.randomUUID());

const visibleInstances = computed(() =>
    props.instances.filter((instance) =>
        filter.value === 'pending'
            ? instance.status === 'pending'
            : instance.status !== 'pending',
    ),
);
const selectedInstance = computed(
    () =>
        props.instances.find((instance) => instance.id === selectedId.value) ??
        null,
);
const statusLabel = (status: string): string =>
    status
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
const statusClass = (status: string): string => {
    if (status === 'approved') {
        return 'border-schedule/30 bg-schedule/10 text-schedule';
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
        return 'border-schedule bg-schedule text-white';
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
          }).format(new Date(value));
const choose = (instance: Instance): void => {
    selectedId.value = instance.id;
    idempotencyKey.value = crypto.randomUUID();
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
    <Head title="Approval inbox" />
    <div class="space-y-8">
        <WorkspacePageHeader
            section="Approvals"
            title="Approval inbox"
            description="Review timetable versions assigned to you, then leave an immutable decision trail."
        >
            <template #metadata
                ><span
                    >{{
                        props.instances.filter(
                            (instance) => instance.status === 'pending',
                        ).length
                    }}
                    open requests</span
                ><span>Decisions are snapshot-backed</span></template
            >
            <template #actions>
                <Button
                    v-if="props.canManageApprovals"
                    variant="outline"
                    as-child
                    ><Link :href="workflows(organization?.slug ?? '').url"
                        >Workflow designer</Link
                    ></Button
                >
                <Button
                    v-if="props.canManageApprovals"
                    variant="outline"
                    as-child
                    ><Link :href="signatories(organization?.slug ?? '').url"
                        >Signatory profiles</Link
                    ></Button
                >
                <Button variant="outline" as-child
                    ><Link :href="inbox(organization?.slug ?? '').url"
                        >Refresh inbox</Link
                    ></Button
                >
            </template>
        </WorkspacePageHeader>

        <div
            class="flex flex-wrap items-center gap-2 border-b border-border pb-3"
        >
            <Button
                :variant="filter === 'pending' ? 'default' : 'ghost'"
                size="sm"
                @click="filter = 'pending'"
                ><Clock3 /> Needs attention</Button
            >
            <Button
                :variant="filter === 'history' ? 'default' : 'ghost'"
                size="sm"
                @click="filter = 'history'"
                ><History /> History</Button
            >
        </div>

        <WorkspaceState
            v-if="visibleInstances.length === 0"
            variant="empty"
            :title="
                filter === 'pending'
                    ? 'Nothing is waiting on you'
                    : 'No approval history yet'
            "
            :description="
                filter === 'pending'
                    ? 'New timetable submissions will appear here when a workflow step makes you eligible.'
                    : 'Completed approval decisions will remain available here for historical review.'
            "
        />

        <div
            v-else
            class="grid gap-6 xl:grid-cols-[minmax(18rem,0.72fr)_minmax(0,1.4fr)]"
        >
            <div class="space-y-3">
                <button
                    v-for="instance in visibleInstances"
                    :key="instance.id"
                    type="button"
                    class="w-full rounded-lg border bg-card p-4 text-left transition hover:border-schedule/50 focus-visible:ring-2 focus-visible:ring-ring"
                    :class="
                        selectedId === instance.id
                            ? 'border-schedule shadow-sm'
                            : 'border-border'
                    "
                    @click="choose(instance)"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-semibold text-foreground">
                                {{ instance.timetable_name }}
                            </p>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Version {{ instance.version_number }} ·
                                {{ instance.workflow_name }}
                            </p>
                        </div>
                        <Badge
                            variant="outline"
                            :class="statusClass(instance.status)"
                            >{{ statusLabel(instance.status) }}</Badge
                        >
                    </div>
                    <div
                        class="mt-4 flex items-center justify-between gap-3 text-xs text-muted-foreground"
                    >
                        <span>Submitted by {{ instance.submitter_name }}</span
                        ><span>{{ formatDate(instance.updated_at) }}</span>
                    </div>
                </button>
            </div>

            <section
                v-if="selectedInstance"
                class="space-y-6 rounded-lg border bg-card p-5 text-card-foreground sm:p-7"
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
                        <h2 class="mt-2 text-2xl font-semibold tracking-tight">
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
                        Status timeline
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
                            <h3 class="font-semibold">Your decision is due</h3>
                            <p
                                class="mt-1 text-sm leading-6 text-muted-foreground"
                            >
                                Choose a decision and add context for the next
                                reviewer or timetable editor.
                            </p>
                        </div>
                    </div>
                    <Form
                        v-bind="
                            decide.form([
                                organization?.slug ?? '',
                                selectedInstance.id,
                            ])
                        "
                        class="mt-5 space-y-4"
                        v-slot="{ errors, processing }"
                    >
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
        </div>
    </div>
</template>
