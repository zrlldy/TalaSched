<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import {
    Archive,
    ArrowRight,
    Check,
    ChevronRight,
    CircleAlert,
    CopyPlus,
    GitCompareArrows,
    LoaderCircle,
    RadioTower,
    RotateCcw,
    Send,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import TimetableVersionStatusBadge from '@/components/scheduling/TimetableVersionStatus.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    compare,
    clone,
    publish,
    rollback,
    submit,
} from '@/routes/scheduling/timetables/versions';
import type { TimetableVersionStatus } from '@/types';

type VersionOption = {
    id: string;
    number: number;
    status: TimetableVersionStatus;
    entry_count: number;
    created_at: string | null;
    submitted_at: string | null;
    published_at: string | null;
};
type WorkflowOption = { id: string; name: string; version: number };
type Props = {
    organizationSlug: string;
    timetableId: string;
    versions: VersionOption[];
    workflows: WorkflowOption[];
    selectedVersionId: string;
    canManageVersions: boolean;
    canSubmitVersions: boolean;
};
type MutationResponse = {
    data: {
        attributes: {
            id: string;
            status: TimetableVersionStatus;
            number?: number;
        };
    };
};
type ComparisonResponse = {
    data: {
        attributes: {
            summary: Record<
                | 'added'
                | 'removed'
                | 'moved'
                | 'reassigned'
                | 'changed'
                | 'total',
                number
            >;
            changes: { kind: string; logical_id: string }[];
            from_version: { id: string; number: number };
            to_version: { id: string; number: number };
        };
    };
};

const props = defineProps<Props>();
const emit = defineEmits<{
    selectVersion: [id: string];
    versionChanged: [id: string];
}>();
const selectedId = ref(props.selectedVersionId);
const compareTargetId = ref<string | null>(null);
const selectedWorkflowId = ref(props.workflows[0]?.id ?? '');
const actionError = ref<string | null>(null);
const comparisonError = ref<string | null>(null);
const comparison = ref<ComparisonResponse['data']['attributes'] | null>(null);
const actionHttp = useHttp<Record<string, never>, MutationResponse>({});
const submitHttp = useHttp<{ workflow_id: string }, MutationResponse>({
    workflow_id: '',
});
const comparisonHttp = useHttp<Record<string, never>, ComparisonResponse>({});

watch(
    () => props.selectedVersionId,
    (id) => {
        selectedId.value = id;
    },
);
watch(
    () => props.workflows,
    (workflows) => {
        if (
            !workflows.some(
                (workflow) => workflow.id === selectedWorkflowId.value,
            )
        ) {
            selectedWorkflowId.value = workflows[0]?.id ?? '';
        }
    },
);

const selectedVersion = computed(
    () =>
        props.versions.find((version) => version.id === selectedId.value) ??
        null,
);
const comparisonCandidates = computed(() =>
    props.versions.filter((version) => version.id !== selectedId.value),
);
const hasComparison = computed(() => comparison.value !== null);
const isActionProcessing = computed(
    () => actionHttp.processing || submitHttp.processing,
);
const comparisonKinds = [
    'added',
    'removed',
    'moved',
    'reassigned',
    'changed',
] as const;
const canPublish = computed(
    () =>
        props.canManageVersions && selectedVersion.value?.status === 'approved',
);
const canRollback = computed(
    () =>
        props.canManageVersions &&
        (selectedVersion.value?.status === 'published' ||
            selectedVersion.value?.status === 'superseded'),
);
const canSubmit = computed(
    () =>
        props.canSubmitVersions &&
        props.workflows.length > 0 &&
        (selectedVersion.value?.status === 'draft' ||
            selectedVersion.value?.status === 'changes_requested'),
);
const formatDate = (value: string | null): string => {
    if (value === null) {
        return 'Not yet';
    }

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(new Date(value));
};
const actionMessage = (error: unknown, fallback: string): string =>
    error instanceof Error ? error.message : fallback;
const versionArgs = (id: string) =>
    [props.organizationSlug, props.timetableId, id] as [string, string, string];

const refreshAfterChange = (id: string): void => {
    selectedId.value = id;
    comparison.value = null;
    comparisonError.value = null;
    emit('versionChanged', id);
};
const runClone = async (): Promise<void> => {
    if (selectedVersion.value === null) {
        return;
    }

    actionError.value = null;

    try {
        const response = await actionHttp.post(
            clone.url(versionArgs(selectedVersion.value.id)),
        );
        refreshAfterChange(response.data.attributes.id);
    } catch (error) {
        actionError.value = actionMessage(
            error,
            'The version could not be cloned.',
        );
    }
};
const runPublish = async (): Promise<void> => {
    if (selectedVersion.value === null) {
        return;
    }

    actionError.value = null;

    try {
        const response = await actionHttp.post(
            publish.url(versionArgs(selectedVersion.value.id)),
        );
        refreshAfterChange(response.data.attributes.id);
    } catch (error) {
        actionError.value = actionMessage(
            error,
            'The version could not be published.',
        );
    }
};
const runRollback = async (): Promise<void> => {
    if (selectedVersion.value === null) {
        return;
    }

    actionError.value = null;

    try {
        const response = await actionHttp.post(
            rollback.url(versionArgs(selectedVersion.value.id)),
        );
        refreshAfterChange(response.data.attributes.id);
    } catch (error) {
        actionError.value = actionMessage(
            error,
            'The historical version could not be rolled back.',
        );
    }
};
const runSubmit = async (): Promise<void> => {
    if (selectedVersion.value === null || selectedWorkflowId.value === '') {
        return;
    }

    actionError.value = null;

    try {
        submitHttp.workflow_id = selectedWorkflowId.value;
        const response = await submitHttp.post(
            submit.url(versionArgs(selectedVersion.value.id)),
        );
        refreshAfterChange(response.data.attributes.id);
    } catch (error) {
        actionError.value = actionMessage(
            error,
            'The version could not be submitted for review.',
        );
    }
};
const runCompare = async (): Promise<void> => {
    if (selectedVersion.value === null || compareTargetId.value === null) {
        return;
    }

    comparisonError.value = null;

    try {
        const response = await comparisonHttp.submit(
            compare([props.organizationSlug, props.timetableId], {
                query: {
                    from_version_id: compareTargetId.value,
                    to_version_id: selectedVersion.value.id,
                },
            }),
        );
        comparison.value = response.data.attributes;
    } catch (error) {
        comparisonError.value = actionMessage(
            error,
            'The version comparison could not be loaded.',
        );
    }
};
const selectVersion = (): void => {
    if (selectedId.value !== '') {
        comparison.value = null;
        emit('selectVersion', selectedId.value);
    }
};
</script>

<template>
    <section
        aria-labelledby="version-ledger-heading"
        class="grid gap-3 border border-border bg-card p-3 sm:p-4 xl:grid-cols-[minmax(15rem,0.9fr)_minmax(0,1.1fr)]"
    >
        <div class="min-w-0">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p
                        class="font-schedule text-[0.625rem] font-semibold tracking-[0.17em] text-schedule uppercase"
                    >
                        Version ledger
                    </p>
                    <h2
                        id="version-ledger-heading"
                        class="mt-1 text-base font-semibold"
                    >
                        History with a next move
                    </h2>
                </div>
                <Archive
                    class="size-5 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
            </div>

            <div class="mt-3 grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto]">
                <label class="grid gap-1.5">
                    <span
                        class="font-schedule text-[0.625rem] font-semibold tracking-[0.12em] text-muted-foreground uppercase"
                    >
                        Inspect version
                    </span>
                    <select
                        v-model="selectedId"
                        class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-xs transition outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        @change="selectVersion"
                    >
                        <option
                            v-for="version in versions"
                            :key="version.id"
                            :value="version.id"
                        >
                            v{{ version.number }} ·
                            {{ version.status.replace('_', ' ') }}
                        </option>
                    </select>
                </label>
                <div v-if="selectedVersion" class="flex items-end">
                    <TimetableVersionStatusBadge
                        :status="selectedVersion.status"
                    />
                </div>
            </div>

            <div
                v-if="selectedVersion"
                class="mt-3 grid grid-cols-3 gap-2 font-schedule text-[0.6875rem]"
            >
                <div class="border border-border/80 bg-muted/20 p-2">
                    <span class="block text-muted-foreground">Entries</span>
                    <strong class="mt-1 block text-sm text-foreground">{{
                        selectedVersion.entry_count
                    }}</strong>
                </div>
                <div class="border border-border/80 bg-muted/20 p-2">
                    <span class="block text-muted-foreground">Created</span>
                    <strong class="mt-1 block text-xs text-foreground">{{
                        formatDate(selectedVersion.created_at)
                    }}</strong>
                </div>
                <div class="border border-border/80 bg-muted/20 p-2">
                    <span class="block text-muted-foreground">Published</span>
                    <strong class="mt-1 block text-xs text-foreground">{{
                        formatDate(selectedVersion.published_at)
                    }}</strong>
                </div>
            </div>

            <div class="mt-3 flex flex-wrap gap-2">
                <Button
                    v-if="canManageVersions"
                    variant="outline"
                    size="sm"
                    type="button"
                    class="gap-1.5"
                    :disabled="isActionProcessing || selectedVersion === null"
                    @click="runClone"
                >
                    <CopyPlus class="size-3.5" aria-hidden="true" />
                    Clone draft
                </Button>
                <Button
                    v-if="canPublish"
                    size="sm"
                    type="button"
                    class="gap-1.5"
                    :disabled="isActionProcessing"
                    @click="runPublish"
                >
                    <RadioTower class="size-3.5" aria-hidden="true" />
                    Publish
                </Button>
                <Button
                    v-if="canRollback"
                    variant="outline"
                    size="sm"
                    type="button"
                    class="gap-1.5"
                    :disabled="isActionProcessing"
                    @click="runRollback"
                >
                    <RotateCcw class="size-3.5" aria-hidden="true" />
                    Roll back
                </Button>
            </div>

            <div
                v-if="
                    canSubmit || (canSubmitVersions && workflows.length === 0)
                "
                class="mt-3 border-t border-border pt-3"
            >
                <div
                    v-if="workflows.length > 0"
                    class="flex flex-col gap-2 sm:flex-row sm:items-end"
                >
                    <label class="grid min-w-0 flex-1 gap-1.5">
                        <span
                            class="font-schedule text-[0.625rem] font-semibold tracking-[0.12em] text-muted-foreground uppercase"
                        >
                            Review workflow
                        </span>
                        <select
                            v-model="selectedWorkflowId"
                            class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-xs transition outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        >
                            <option
                                v-for="workflow in workflows"
                                :key="workflow.id"
                                :value="workflow.id"
                            >
                                {{ workflow.name }} · v{{ workflow.version }}
                            </option>
                        </select>
                    </label>
                    <Button
                        variant="secondary"
                        size="sm"
                        type="button"
                        class="gap-1.5"
                        :disabled="!canSubmit || isActionProcessing"
                        @click="runSubmit"
                    >
                        <Send class="size-3.5" aria-hidden="true" />
                        Submit for review
                    </Button>
                </div>
                <p
                    v-else
                    class="flex items-start gap-2 text-xs leading-5 text-muted-foreground"
                >
                    <CircleAlert
                        class="mt-0.5 size-3.5 shrink-0 text-warning"
                        aria-hidden="true"
                    />
                    Activate an approval workflow before submitting this version
                    for review.
                </p>
            </div>

            <p
                v-if="isActionProcessing"
                class="mt-3 flex items-center gap-2 text-xs text-muted-foreground"
                role="status"
            >
                <LoaderCircle
                    class="size-3.5 animate-spin"
                    aria-hidden="true"
                />
                Saving version transition…
            </p>
            <p
                v-if="actionError"
                class="mt-3 flex items-start gap-2 text-xs leading-5 text-conflict"
                role="alert"
            >
                <CircleAlert
                    class="mt-0.5 size-3.5 shrink-0"
                    aria-hidden="true"
                />
                {{ actionError }}
            </p>
        </div>

        <div
            class="min-w-0 border-t border-border pt-3 xl:border-t-0 xl:border-l xl:pt-0 xl:pl-4"
        >
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p
                        class="font-schedule text-[0.625rem] font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                    >
                        Compare snapshots
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        See what changed before you publish or submit.
                    </p>
                </div>
                <GitCompareArrows
                    class="size-5 text-schedule"
                    aria-hidden="true"
                />
            </div>
            <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-end">
                <label class="grid min-w-0 flex-1 gap-1.5">
                    <span
                        class="font-schedule text-[0.625rem] font-semibold tracking-[0.12em] text-muted-foreground uppercase"
                    >
                        Compare from
                    </span>
                    <select
                        v-model="compareTargetId"
                        class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-xs transition outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        <option :value="null">Choose a version</option>
                        <option
                            v-for="version in comparisonCandidates"
                            :key="version.id"
                            :value="version.id"
                        >
                            v{{ version.number }} ·
                            {{ version.status.replace('_', ' ') }}
                        </option>
                    </select>
                </label>
                <Button
                    variant="outline"
                    size="sm"
                    type="button"
                    class="gap-1.5"
                    :disabled="
                        comparisonHttp.processing ||
                        compareTargetId === null ||
                        selectedVersion === null
                    "
                    @click="runCompare"
                >
                    <GitCompareArrows class="size-3.5" aria-hidden="true" />
                    Compare
                </Button>
            </div>

            <div
                v-if="comparisonHttp.processing"
                class="mt-3 flex items-center gap-2 text-xs text-muted-foreground"
                role="status"
            >
                <LoaderCircle
                    class="size-3.5 animate-spin"
                    aria-hidden="true"
                />
                Reading the two snapshots…
            </div>
            <p
                v-if="comparisonError"
                class="mt-3 text-xs leading-5 text-conflict"
                role="alert"
            >
                {{ comparisonError }}
            </p>
            <div
                v-if="hasComparison && comparison"
                class="mt-3 border border-border bg-muted/15 p-3"
            >
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <Badge variant="outline"
                        >v{{ comparison.from_version.number }}</Badge
                    >
                    <ArrowRight
                        class="size-3.5 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Badge variant="outline"
                        >v{{ comparison.to_version.number }}</Badge
                    >
                    <span class="font-schedule text-muted-foreground"
                        >{{ comparison.summary.total }} changed lineages</span
                    >
                </div>
                <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-5">
                    <div
                        v-for="kind in comparisonKinds"
                        :key="kind"
                        class="border border-border/70 bg-background px-2 py-1.5 text-center"
                    >
                        <strong class="block text-sm">{{
                            comparison.summary[kind]
                        }}</strong>
                        <span
                            class="font-schedule text-[0.5625rem] tracking-[0.08em] text-muted-foreground uppercase"
                            >{{ kind }}</span
                        >
                    </div>
                </div>
                <ul
                    v-if="comparison.changes.length > 0"
                    class="mt-3 grid max-h-28 gap-1 overflow-y-auto text-xs text-muted-foreground"
                >
                    <li
                        v-for="change in comparison.changes"
                        :key="`${change.logical_id}-${change.kind}`"
                        class="flex items-center gap-2"
                    >
                        <Check
                            class="size-3 text-schedule"
                            aria-hidden="true"
                        />
                        <span class="font-medium text-foreground capitalize">{{
                            change.kind
                        }}</span>
                        <span class="truncate font-schedule">{{
                            change.logical_id
                        }}</span>
                    </li>
                </ul>
                <p v-else class="mt-3 text-xs text-muted-foreground">
                    These snapshots have no changed schedule lineages.
                </p>
            </div>
            <p
                v-else-if="!comparisonHttp.processing && !comparisonError"
                class="mt-3 flex items-center gap-2 text-xs text-muted-foreground"
            >
                <ChevronRight
                    class="size-3.5 text-schedule"
                    aria-hidden="true"
                />
                Pick an earlier snapshot to open its change ledger.
            </p>
        </div>
    </section>
</template>
