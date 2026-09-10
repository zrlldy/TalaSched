<script setup lang="ts">
import { Head, router, useHttp, usePage } from '@inertiajs/vue3';
import {
    AlertCircle,
    CalendarDays,
    Check,
    ChevronRight,
    Clock3,
    Command,
    Filter,
    Search,
    RefreshCw,
    Save,
    SlidersHorizontal,
    X,
} from '@lucide/vue';
import {
    computed,
    nextTick,
    onMounted,
    onUnmounted,
    reactive,
    ref,
    watch,
} from 'vue';
import MinuteTimeInput from '@/components/MinuteTimeInput.vue';
import AddScheduleEntry from '@/components/scheduling/AddScheduleEntry.vue';
import SchedulingConflictList from '@/components/scheduling/SchedulingConflictList.vue';
import TimetableVersionPanel from '@/components/scheduling/TimetableVersionPanel.vue';
import TimetableVersionStatusBadge from '@/components/scheduling/TimetableVersionStatus.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import WorkspaceState from '@/components/WorkspaceState.vue';
import { saveScheduleEntry, schedulingError } from '@/lib/scheduling';
import { dashboard } from '@/routes';
import { update } from '@/routes/scheduling/entries';
import { views } from '@/routes/scheduling/timetables';
import type {
    ScheduleIssue,
    ScheduleOfferingOption,
    TimetableVersionStatus,
    TimetableView,
    TimetableViewEntry,
    TimetableViewScope,
} from '@/types';
import type { Organization } from '@/types';

type Option = { id: string; name: string; code?: string | null; type?: string };
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
type FilterState = {
    scope: TimetableViewScope;
    version_id: string | null;
    resource_id: string | null;
    unit_id: string | null;
    date: string | null;
    weekday: number | null;
};
type Props = {
    view: TimetableView;
    timetableId: string;
    filters: FilterState;
    versions: VersionOption[];
    versionWorkflows: WorkflowOption[];
    resources: Option[];
    units: Option[];
    canManageScheduling: boolean;
    canManageVersions: boolean;
    canSubmitVersions: boolean;
    offeringComponents: ScheduleOfferingOption[];
    emptyOfferingCount: number;
};
type ViewResponse = {
    data: { type: string; attributes: TimetableView };
};
type EntryPayload = {
    lock_version: number;
    weekday: number;
    starts_at_minute: number | '';
    ends_at_minute: number | '';
    delivery_mode: string;
    notes: string | null;
    resources: { resource_id: string; role: string }[];
};
type PaletteCommand = {
    label: string;
    description: string;
    shortcut?: string;
    action: () => void;
};

const props = defineProps<Props>();
const page = usePage();
const currentView = ref<TimetableView>(props.view);
const selectedScope = ref<TimetableViewScope>(props.filters.scope);
const selectedVersionId = ref<string | null>(props.filters.version_id);
const selectedResourceId = ref<string | null>(props.filters.resource_id);
const selectedUnitId = ref<string | null>(props.filters.unit_id);
const selectedDate = ref<string>(props.filters.date ?? '');
const selectedEntryId = ref<string | null>(props.view.entries[0]?.id ?? null);
const loadError = ref<string | null>(null);
const updateError = ref<string | null>(null);
const validationIssues = ref<ScheduleIssue[]>([]);
const saveNotice = ref('');
const commandPaletteOpen = ref(false);
const commandQuery = ref('');
const commandInput = ref<HTMLInputElement | null>(null);
const commandTrigger = ref<HTMLButtonElement | null>(null);
const lastFocusedElement = ref<HTMLElement | null>(null);

const viewHttp = useHttp<Record<string, never>, ViewResponse>({});
const saving = ref(false);
const entryForm = reactive<EntryPayload>({
    lock_version: 1,
    weekday: 1,
    starts_at_minute: 480,
    ends_at_minute: 570,
    delivery_mode: 'physical',
    notes: null,
    resources: [],
});

const weekdays = [
    { value: 1, short: 'Mon', name: 'Monday' },
    { value: 2, short: 'Tue', name: 'Tuesday' },
    { value: 3, short: 'Wed', name: 'Wednesday' },
    { value: 4, short: 'Thu', name: 'Thursday' },
    { value: 5, short: 'Fri', name: 'Friday' },
    { value: 6, short: 'Sat', name: 'Saturday' },
    { value: 7, short: 'Sun', name: 'Sunday' },
];
const scopeOptions: { value: TimetableViewScope; label: string }[] = [
    { value: 'organization', label: 'Organization' },
    { value: 'teacher', label: 'Teacher' },
    { value: 'student_group', label: 'Student group' },
    { value: 'room', label: 'Room' },
    { value: 'unit', label: 'Academic unit' },
];
const organizationSlug = computed(
    () =>
        page.props.currentOrganization?.slug ??
        currentView.value.context.organization.slug,
);
const selectedEntry = computed(
    () =>
        currentView.value.entries.find(
            (entry) => entry.id === selectedEntryId.value,
        ) ?? null,
);
const selectedEntryExceptions = computed(
    () => selectedEntry.value?.exceptions ?? [],
);
const canEditVersion = computed(
    () =>
        props.canManageScheduling &&
        currentView.value.date === null &&
        ['draft', 'changes_requested'].includes(
            currentView.value.context.version.status,
        ),
);
const canEditSelectedEntry = computed(
    () =>
        canEditVersion.value &&
        selectedEntry.value !== null &&
        selectedEntry.value.status !== 'cancelled',
);
const selectedResourceOptions = computed(() => {
    const typeByScope: Partial<Record<TimetableViewScope, string>> = {
        teacher: 'faculty',
        student_group: 'student_group',
        room: 'room',
    };
    const expectedType = typeByScope[selectedScope.value];

    return expectedType === undefined
        ? []
        : props.resources.filter((resource) => resource.type === expectedType);
});
const filteredEntries = computed(() => currentView.value.entries);
const gridStart = computed(() => {
    const times = filteredEntries.value
        .map((entry) => entry.starts_at_minute)
        .filter((minute): minute is number => minute !== null);

    return Math.min(
        420,
        times.length > 0 ? Math.floor(Math.min(...times) / 60) * 60 : 420,
    );
});
const gridEnd = computed(() => {
    const times = filteredEntries.value
        .map((entry) => entry.ends_at_minute)
        .filter((minute): minute is number => minute !== null);

    return Math.max(
        1140,
        times.length > 0 ? Math.ceil(Math.max(...times) / 60) * 60 : 1140,
    );
});
const pixelsPerMinute = 1.1;
const gridHeight = computed(() =>
    Math.max(520, (gridEnd.value - gridStart.value) * pixelsPerMinute),
);
const timeLabels = computed(() => {
    const labels: number[] = [];

    for (let minute = gridStart.value; minute <= gridEnd.value; minute += 60) {
        labels.push(minute);
    }

    return labels;
});
const hasSelectedDate = computed(() => selectedDate.value !== '');
const selectedDateLabel = computed(() =>
    selectedDate.value === ''
        ? 'All dates'
        : new Intl.DateTimeFormat(undefined, {
              weekday: 'long',
              month: 'long',
              day: 'numeric',
              year: 'numeric',
          }).format(new Date(`${selectedDate.value}T00:00:00`)),
);
const statusLabel = (status: TimetableViewEntry['status']): string => {
    const labels: Record<TimetableViewEntry['status'], string> = {
        scheduled: 'Scheduled',
        cancelled: 'Cancelled',
        rescheduled: 'Rescheduled',
        replaced: 'Replaced',
    };

    return labels[status];
};
const statusClass = (status: TimetableViewEntry['status']): string => {
    const classes: Record<TimetableViewEntry['status'], string> = {
        scheduled: 'border-schedule/30 bg-schedule/10 text-schedule',
        cancelled: 'border-conflict/30 bg-conflict/10 text-foreground',
        rescheduled: 'border-warning/40 bg-warning/15 text-foreground',
        replaced: 'border-warning/40 bg-warning/15 text-foreground',
    };

    return classes[status];
};
const entryStateClass = (entry: TimetableViewEntry): string => {
    if (entry.status === 'cancelled') {
        return 'border-conflict/45 bg-conflict/10 text-foreground';
    }

    if (entry.status === 'rescheduled' || entry.status === 'replaced') {
        return 'border-warning/45 bg-warning/10 text-foreground';
    }

    return 'border-schedule/35 bg-schedule/10 text-foreground';
};

const formatMinutes = (minutes: number | null): string => {
    if (minutes === null) {
        return 'No time';
    }

    if (minutes === 1440) {
        return 'Midnight';
    }

    const hours = Math.floor(minutes / 60);
    const remainder = minutes % 60;
    const suffix = hours >= 12 ? 'PM' : 'AM';
    const hour = hours % 12 || 12;

    return `${hour}:${remainder.toString().padStart(2, '0')} ${suffix}`;
};
const formatTimeRange = (entry: TimetableViewEntry): string =>
    `${formatMinutes(entry.starts_at_minute)} - ${formatMinutes(entry.ends_at_minute)}`;
const selectedEntryTimeRange = computed(() =>
    selectedEntry.value === null
        ? 'No time'
        : formatTimeRange(selectedEntry.value),
);
const entriesForDay = (weekday: number): TimetableViewEntry[] =>
    filteredEntries.value.filter((entry) => entry.weekday === weekday);
const entryStyle = (entry: TimetableViewEntry): Record<string, string> => {
    const starts = entry.starts_at_minute ?? gridStart.value;
    const ends = entry.ends_at_minute ?? starts + 30;

    return {
        top: `${(starts - gridStart.value) * pixelsPerMinute}px`,
        height: `${Math.max(2.2 * 16, (ends - starts) * pixelsPerMinute)}px`,
    };
};
const resourceNames = (entry: TimetableViewEntry): string =>
    entry.resources
        .filter(
            (resource) =>
                resource.role === 'room' || resource.role === 'instructor',
        )
        .map((resource) => resource.name)
        .join(' / ');
const optionLabel = (option: Option): string =>
    option.code ? `${option.code} / ${option.name}` : option.name;

const syncEditorFromEntry = (entry: TimetableViewEntry | null): void => {
    if (entry === null) {
        validationIssues.value = [];
        saveNotice.value = '';

        return;
    }

    const payload = {
        lock_version: entry.lock_version,
        weekday: entry.weekday,
        starts_at_minute: entry.starts_at_minute ?? 480,
        ends_at_minute: entry.ends_at_minute ?? 570,
        delivery_mode: entry.delivery_mode,
        notes: entry.notes,
        resources: entry.resources.map((resource) => ({
            resource_id: resource.id,
            role: resource.role,
        })),
    };
    Object.assign(entryForm, payload);
    validationIssues.value = [];
    saveNotice.value = '';
    updateError.value = null;
};

watch(selectedEntry, syncEditorFromEntry, { immediate: true });
watch(
    () => ({ ...entryForm }),
    () => {
        saveNotice.value = '';
    },
    { deep: true },
);
watch(currentView, (view) => {
    if (!view.entries.some((entry) => entry.id === selectedEntryId.value)) {
        selectedEntryId.value = view.entries[0]?.id ?? null;
    }
});

const updateBrowserQuery = (): void => {
    const query = new URLSearchParams({ scope: selectedScope.value });

    if (selectedVersionId.value) {
        query.set('version_id', selectedVersionId.value);
    }

    if (selectedResourceId.value && selectedScope.value !== 'unit') {
        query.set('resource_id', selectedResourceId.value);
    }

    if (selectedUnitId.value && selectedScope.value === 'unit') {
        query.set('unit_id', selectedUnitId.value);
    }

    if (selectedDate.value) {
        query.set('date', selectedDate.value);
    }

    window.history.replaceState(
        window.history.state,
        '',
        `${window.location.pathname}?${query.toString()}`,
    );
};

const refreshView = async (): Promise<void> => {
    loadError.value = null;
    updateBrowserQuery();

    try {
        const response = await viewHttp.submit(
            views([organizationSlug.value, props.timetableId], {
                query: {
                    scope: selectedScope.value,
                    version_id: selectedVersionId.value ?? undefined,
                    resource_id: selectedResourceId.value ?? undefined,
                    unit_id: selectedUnitId.value ?? undefined,
                    date: selectedDate.value || undefined,
                },
            }),
        );
        currentView.value = response.data.attributes;
    } catch (error) {
        loadError.value =
            error instanceof Error
                ? error.message
                : 'The timetable view could not be loaded.';
    }
};

const resetFilters = (): void => {
    selectedScope.value = 'organization';
    selectedVersionId.value = null;
    selectedResourceId.value = null;
    selectedUnitId.value = null;
    selectedDate.value = '';
    void refreshView();
};
const selectVersion = (versionId: string): void => {
    selectedVersionId.value = versionId;
    void refreshView();
};
const handleVersionChanged = (versionId: string): void => {
    selectedVersionId.value = versionId;
    void refreshView();
    router.reload({ only: ['versions', 'versionWorkflows'] });
};
const scopeChanged = (): void => {
    selectedResourceId.value = null;
    selectedUnitId.value = null;
};
const selectEntry = (entry: TimetableViewEntry): void => {
    selectedEntryId.value = entry.id;
};

const saveSelectedEntry = async (): Promise<void> => {
    const entry = selectedEntry.value;

    if (entry === null || !canEditSelectedEntry.value || saving.value) {
        return;
    }

    updateError.value = null;
    validationIssues.value = [];
    saveNotice.value = '';

    saving.value = true;

    try {
        await saveScheduleEntry(
            update([organizationSlug.value, entry.id]),
            entryForm,
        );
        await refreshView();
        await nextTick();
        saveNotice.value = 'Class saved. Conflict checks passed.';
    } catch (error) {
        const failure = schedulingError(error);
        updateError.value = failure.message;
        validationIssues.value = failure.issues;
    } finally {
        saving.value = false;
    }
};
const handleEntryCreated = async (id: string): Promise<void> => {
    selectedScope.value = 'organization';
    selectedResourceId.value = null;
    selectedUnitId.value = null;
    selectedEntryId.value = id;
    await refreshView();
    router.reload({ only: ['versions'] });
};

const closeCommandPalette = (): void => {
    commandPaletteOpen.value = false;
    commandQuery.value = '';
    const elementToRestore = lastFocusedElement.value;
    lastFocusedElement.value = null;
    void nextTick(() => elementToRestore?.focus());
};
const openCommandPalette = async (): Promise<void> => {
    lastFocusedElement.value =
        document.activeElement instanceof HTMLElement
            ? document.activeElement
            : commandTrigger.value;
    commandPaletteOpen.value = true;
    await nextTick();
    commandInput.value?.focus();
};
const paletteCommands = computed<PaletteCommand[]>(() => [
    {
        label: 'Reset timetable view',
        description: 'Clear scope, version, resource, and date filters',
        action: resetFilters,
    },
    {
        label: 'Refresh schedule',
        description: 'Reload the current timetable view',
        action: () => void refreshView(),
    },
    {
        label: 'Select first entry',
        description: 'Move the inspector to the first visible class',
        action: () => {
            selectedEntryId.value = currentView.value.entries[0]?.id ?? null;
        },
    },
    {
        label: 'Check and save entry',
        description: 'Validate the selected class before saving it',
        shortcut: '⌘S',
        action: () => void saveSelectedEntry(),
    },
]);
const filteredCommands = computed(() => {
    const query = commandQuery.value.trim().toLowerCase();

    if (query === '') {
        return paletteCommands.value;
    }

    return paletteCommands.value.filter((command) =>
        `${command.label} ${command.description}`.toLowerCase().includes(query),
    );
});
const runPaletteCommand = (command: PaletteCommand): void => {
    closeCommandPalette();
    command.action();
};
const runFirstCommand = (): void => {
    const command = filteredCommands.value[0];

    if (command !== undefined) {
        runPaletteCommand(command);
    }
};
const handleGlobalKeydown = (event: KeyboardEvent): void => {
    const modifierPressed = event.metaKey || event.ctrlKey;

    if (modifierPressed && event.shiftKey && event.key.toLowerCase() === 'k') {
        event.preventDefault();

        if (commandPaletteOpen.value) {
            closeCommandPalette();
        } else {
            void openCommandPalette();
        }

        return;
    }

    if (modifierPressed && event.key.toLowerCase() === 's') {
        event.preventDefault();
        void saveSelectedEntry();

        return;
    }

    if (event.key === 'Escape' && commandPaletteOpen.value) {
        event.preventDefault();
        closeCommandPalette();
    }
};

onMounted(() => window.addEventListener('keydown', handleGlobalKeydown));
onUnmounted(() => window.removeEventListener('keydown', handleGlobalKeydown));

defineOptions({
    layout: (layoutProps: { currentOrganization?: Organization | null }) => ({
        breadcrumbs: [
            {
                title: 'Timetable workspace',
                href: layoutProps.currentOrganization
                    ? dashboard(layoutProps.currentOrganization.slug)
                    : '/',
            },
        ],
    }),
});
</script>

<template>
    <Head :title="`${currentView.context.timetable.name} workspace`" />

    <div
        class="flex min-h-full flex-col gap-3 bg-background px-3 py-3 sm:px-5 lg:px-6"
    >
        <header
            class="flex flex-col gap-3 border-b border-border pb-3 xl:flex-row xl:items-center xl:justify-between"
        >
            <div class="flex min-w-0 items-start gap-3">
                <div
                    aria-hidden="true"
                    class="mt-1 flex h-9 w-1.5 shrink-0 flex-col gap-1"
                >
                    <span class="flex-1 rounded-xs bg-schedule" />
                    <span class="h-2 rounded-xs bg-warning" />
                </div>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <p
                            class="font-schedule text-[0.625rem] font-semibold tracking-[0.18em] text-muted-foreground uppercase"
                        >
                            Scheduling / timetable
                        </p>
                        <span class="text-muted-foreground" aria-hidden="true"
                            >/</span
                        >
                        <span
                            class="font-schedule text-[0.625rem] text-muted-foreground"
                            >{{ currentView.context.period.name }}</span
                        >
                    </div>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <h1
                            class="text-xl font-semibold tracking-tight text-foreground sm:text-2xl"
                        >
                            {{ currentView.context.timetable.name }}
                        </h1>
                        <TimetableVersionStatusBadge
                            :status="currentView.context.version.status"
                        />
                    </div>
                    <div
                        class="mt-1 flex flex-wrap gap-x-3 gap-y-1 font-schedule text-[0.6875rem] text-muted-foreground"
                    >
                        <span
                            >{{ currentView.entries.length }} classes in
                            view</span
                        >
                        <span>{{
                            currentView.context.organization.timezone
                        }}</span>
                        <span>Saved in URL</span>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-2 self-start xl:self-auto">
                <AddScheduleEntry
                    v-if="canEditVersion"
                    :key="currentView.context.version.id"
                    :organization-slug="organizationSlug"
                    :version-id="currentView.context.version.id"
                    :timezone="currentView.context.organization.timezone"
                    :offerings="offeringComponents"
                    :empty-offering-count="emptyOfferingCount"
                    :rooms="
                        resources.filter((resource) => resource.type === 'room')
                    "
                    @created="handleEntryCreated"
                />
                <Button
                    ref="commandTrigger"
                    variant="outline"
                    size="sm"
                    type="button"
                    class="gap-2"
                    @click="openCommandPalette"
                >
                    <Command class="size-3.5" aria-hidden="true" />
                    <span>Commands</span>
                    <kbd
                        class="hidden rounded border border-border bg-muted px-1.5 py-0.5 font-schedule text-[0.625rem] text-muted-foreground sm:inline"
                        >⇧⌘K</kbd
                    >
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    type="button"
                    aria-label="Reset timetable view"
                    title="Reset timetable view"
                    @click="resetFilters"
                >
                    <RefreshCw class="size-4" />
                </Button>
            </div>
        </header>

        <div
            v-if="!canEditVersion"
            class="flex flex-wrap items-center justify-between gap-3 rounded-md border bg-muted/30 px-4 py-3 text-sm"
            role="status"
        >
            <p v-if="!canManageScheduling">
                This timetable is read only for your current access. Ask your
                administrator for scheduling access and an enabled manual
                scheduling plan.
            </p>
            <template v-else-if="currentView.date">
                <p>
                    Date view is read only. Return to the weekly timetable to
                    add or edit classes in a draft.
                </p>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    @click="
                        selectedDate = '';
                        refreshView();
                    "
                    >Show weekly timetable</Button
                >
            </template>
            <p v-else>
                This version is
                {{ currentView.context.version.status.replaceAll('_', ' ') }}.
                Select or create a draft in Versions to add classes.
            </p>
        </div>

        <div
            class="flex flex-wrap items-center justify-between gap-2 border-b border-border py-2 font-schedule text-[0.6875rem]"
        >
            <div class="flex items-center gap-2">
                <AlertCircle
                    class="size-3.5"
                    :class="
                        validationIssues.length > 0
                            ? 'text-conflict'
                            : saveNotice
                              ? 'text-available'
                              : 'text-warning'
                    "
                    aria-hidden="true"
                />
                <span class="relative flex size-2.5" aria-hidden="true">
                    <span
                        class="absolute inline-flex size-full rounded-full"
                        :class="
                            validationIssues.length > 0
                                ? 'bg-conflict'
                                : saveNotice
                                  ? 'bg-available'
                                  : 'bg-warning'
                        "
                    />
                </span>
                <span
                    class="font-semibold"
                    :class="
                        validationIssues.length > 0
                            ? 'text-conflict'
                            : saveNotice
                              ? 'text-available'
                              : 'text-warning'
                    "
                >
                    {{
                        validationIssues.length > 0
                            ? `${validationIssues.length} conflict${validationIssues.length === 1 ? '' : 's'} found`
                            : saveNotice
                              ? 'Class saved'
                              : 'Conflicts checked before save'
                    }}
                </span>
                <span class="hidden text-muted-foreground sm:inline">{{
                    selectedEntry
                        ? `Selected: ${selectedEntry.offering.subject.code ?? selectedEntry.offering.subject.name}`
                        : 'Select a class to inspect it'
                }}</span>
            </div>
            <span class="text-muted-foreground">{{
                hasSelectedDate ? selectedDateLabel : 'Recurring weekly view'
            }}</span>
        </div>

        <section
            aria-labelledby="filter-heading"
            class="border border-border bg-card p-2.5"
        >
            <div
                class="flex flex-col gap-2.5 lg:flex-row lg:items-center lg:justify-between"
            >
                <div class="flex items-center gap-2 px-1">
                    <SlidersHorizontal
                        class="size-4 text-schedule"
                        aria-hidden="true"
                    />
                    <div>
                        <h2 id="filter-heading" class="text-sm font-semibold">
                            View filters
                        </h2>
                        <p
                            class="hidden text-xs text-muted-foreground sm:block"
                        >
                            Keep the board focused without leaving the
                            workspace.
                        </p>
                    </div>
                </div>
                <form
                    class="grid gap-2 sm:grid-cols-2 lg:flex lg:flex-wrap lg:items-center"
                    @submit.prevent="refreshView"
                >
                    <div class="min-w-36">
                        <Label for="view-scope" class="sr-only">View by</Label>
                        <select
                            id="view-scope"
                            v-model="selectedScope"
                            class="h-8 w-full rounded-md border border-input bg-background px-2.5 text-xs transition outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            @change="scopeChanged"
                        >
                            <option
                                v-for="scope in scopeOptions"
                                :key="scope.value"
                                :value="scope.value"
                            >
                                View: {{ scope.label }}
                            </option>
                        </select>
                    </div>
                    <div
                        v-if="selectedResourceOptions.length > 0"
                        class="min-w-44"
                    >
                        <Label for="view-resource" class="sr-only"
                            >Resource</Label
                        >
                        <select
                            id="view-resource"
                            v-model="selectedResourceId"
                            class="h-8 w-full rounded-md border border-input bg-background px-2.5 text-xs transition outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        >
                            <option :value="null">All resources</option>
                            <option
                                v-for="resource in selectedResourceOptions"
                                :key="resource.id"
                                :value="resource.id"
                            >
                                {{ optionLabel(resource) }}
                            </option>
                        </select>
                    </div>
                    <div v-if="selectedScope === 'unit'" class="min-w-44">
                        <Label for="view-unit" class="sr-only"
                            >Academic unit</Label
                        >
                        <select
                            id="view-unit"
                            v-model="selectedUnitId"
                            class="h-8 w-full rounded-md border border-input bg-background px-2.5 text-xs transition outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        >
                            <option :value="null">All academic units</option>
                            <option
                                v-for="unit in units"
                                :key="unit.id"
                                :value="unit.id"
                            >
                                {{ optionLabel(unit) }}
                            </option>
                        </select>
                    </div>
                    <div class="min-w-36">
                        <Label for="view-version" class="sr-only"
                            >Version</Label
                        >
                        <select
                            id="view-version"
                            v-model="selectedVersionId"
                            class="h-8 w-full rounded-md border border-input bg-background px-2.5 text-xs transition outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        >
                            <option :value="null">Published or approved</option>
                            <option
                                v-for="version in versions"
                                :key="version.id"
                                :value="version.id"
                            >
                                v{{ version.number }} · {{ version.status }}
                            </option>
                        </select>
                    </div>
                    <div class="min-w-40">
                        <Label for="view-date" class="sr-only"
                            >Specific date</Label
                        >
                        <Input
                            id="view-date"
                            v-model="selectedDate"
                            class="h-8 text-xs"
                            type="date"
                            :min="currentView.context.period.starts_on"
                            :max="currentView.context.period.ends_on"
                        />
                    </div>
                    <Button
                        class="h-8 gap-1.5 px-3 text-xs"
                        size="sm"
                        type="submit"
                        :disabled="viewHttp.processing"
                    >
                        <Filter class="size-3.5" />
                        {{ viewHttp.processing ? 'Loading' : 'Apply' }}
                    </Button>
                </form>
            </div>
        </section>

        <TimetableVersionPanel
            :organization-slug="organizationSlug"
            :timetable-id="timetableId"
            :versions="versions"
            :workflows="versionWorkflows"
            :selected-version-id="
                selectedVersionId ?? currentView.context.version.id
            "
            :can-manage-versions="canManageVersions"
            :can-submit-versions="canSubmitVersions"
            @select-version="selectVersion"
            @version-changed="handleVersionChanged"
        />

        <WorkspaceState
            v-if="loadError"
            variant="error"
            title="The timetable view could not be loaded"
            :description="loadError"
        >
            <template #action
                ><Button variant="outline" type="button" @click="refreshView"
                    >Try again</Button
                ></template
            >
        </WorkspaceState>

        <template v-else>
            <div class="grid min-w-0 gap-3 xl:grid-cols-[minmax(0,1fr)_21rem]">
                <section
                    aria-labelledby="board-heading"
                    class="min-w-0 overflow-hidden border border-border bg-card"
                >
                    <div
                        class="flex flex-col gap-2 border-b border-border px-3 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-4"
                    >
                        <div class="min-w-0">
                            <p
                                class="font-schedule text-[0.625rem] font-semibold tracking-[0.17em] text-schedule uppercase"
                            >
                                Schedule canvas
                            </p>
                            <h2
                                id="board-heading"
                                class="mt-1 truncate text-base font-semibold"
                            >
                                {{
                                    hasSelectedDate
                                        ? selectedDateLabel
                                        : 'Weekly timetable'
                                }}
                            </h2>
                        </div>
                        <div
                            class="flex shrink-0 items-center gap-3 font-schedule text-[0.6875rem] text-muted-foreground"
                        >
                            <span class="inline-flex items-center gap-1.5"
                                ><Clock3 class="size-3.5 text-schedule" />
                                {{ formatMinutes(gridStart) }}–{{
                                    formatMinutes(gridEnd)
                                }}</span
                            >
                            <span class="border-l border-border pl-3"
                                >{{ filteredEntries.length }} entries</span
                            >
                        </div>
                    </div>

                    <div
                        class="hidden overflow-x-auto lg:block"
                        role="grid"
                        aria-label="Weekly timetable"
                    >
                        <div class="min-w-[62rem]">
                            <div
                                class="grid grid-cols-[4.75rem_repeat(7,minmax(8.5rem,1fr))] border-b border-border bg-muted/35"
                                role="row"
                            >
                                <div
                                    class="border-r border-border px-2 py-3 font-schedule text-[0.625rem] font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                                    role="columnheader"
                                >
                                    Time
                                </div>
                                <div
                                    v-for="day in weekdays"
                                    :key="day.value"
                                    class="border-r border-border px-3 py-2.5 last:border-r-0"
                                    role="columnheader"
                                >
                                    <span
                                        class="font-schedule text-[0.625rem] font-semibold tracking-[0.15em] text-muted-foreground uppercase"
                                        >{{ day.short }}</span
                                    >
                                    <span
                                        class="mt-1 block text-xs font-semibold"
                                        >{{ day.name }}</span
                                    >
                                </div>
                            </div>
                            <div
                                class="grid grid-cols-[4.75rem_repeat(7,minmax(8.5rem,1fr))]"
                                role="rowgroup"
                            >
                                <div
                                    class="relative border-r border-border bg-muted/20"
                                    :style="{ height: `${gridHeight}px` }"
                                    aria-hidden="true"
                                >
                                    <span
                                        v-for="label in timeLabels"
                                        :key="label"
                                        class="absolute right-2 -translate-y-1/2 font-schedule text-[0.625rem] text-muted-foreground"
                                        :style="{
                                            top: `${(label - gridStart) * pixelsPerMinute}px`,
                                        }"
                                        >{{ formatMinutes(label) }}</span
                                    >
                                </div>
                                <div
                                    v-for="day in weekdays"
                                    :key="day.value"
                                    class="relative border-r border-border last:border-r-0"
                                    :style="{ height: `${gridHeight}px` }"
                                    role="gridcell"
                                    :aria-label="day.name"
                                >
                                    <div
                                        v-for="label in timeLabels"
                                        :key="label"
                                        class="absolute inset-x-0 border-t border-dashed border-border/70"
                                        :style="{
                                            top: `${(label - gridStart) * pixelsPerMinute}px`,
                                        }"
                                        aria-hidden="true"
                                    />
                                    <button
                                        v-for="entry in entriesForDay(
                                            day.value,
                                        )"
                                        :key="entry.id"
                                        type="button"
                                        class="absolute inset-x-1.5 z-10 overflow-hidden rounded-md border-l-4 p-2 text-left transition hover:border-schedule focus-visible:ring-2 focus-visible:ring-schedule focus-visible:ring-offset-2"
                                        :class="[
                                            entryStateClass(entry),
                                            selectedEntryId === entry.id
                                                ? 'ring-2 ring-schedule ring-offset-1'
                                                : '',
                                        ]"
                                        :style="entryStyle(entry)"
                                        :aria-label="`${entry.offering.subject.name}, ${formatTimeRange(entry)}, ${statusLabel(entry.status)}`"
                                        @click="selectEntry(entry)"
                                    >
                                        <span
                                            class="block truncate font-schedule text-[0.6875rem] font-semibold tracking-wide"
                                            >{{
                                                entry.offering.subject.code ??
                                                entry.offering.subject.name
                                            }}</span
                                        >
                                        <span
                                            class="mt-1 block truncate text-[0.6875rem]"
                                            >{{
                                                entry.offering.student_group
                                                    .name
                                            }}</span
                                        >
                                        <span
                                            class="mt-1 block truncate font-schedule text-[0.625rem] text-muted-foreground"
                                            >{{ formatTimeRange(entry) }}</span
                                        >
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-2 p-3 lg:hidden">
                        <div
                            v-if="filteredEntries.length === 0"
                            class="border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground"
                        >
                            No entries match this view.
                        </div>
                        <button
                            v-for="entry in filteredEntries"
                            :key="entry.id"
                            type="button"
                            class="grid gap-2 rounded-md border-l-4 p-3 text-left transition focus-visible:ring-2 focus-visible:ring-schedule focus-visible:ring-offset-2"
                            :class="[
                                entryStateClass(entry),
                                selectedEntryId === entry.id
                                    ? 'ring-2 ring-schedule ring-offset-1'
                                    : '',
                            ]"
                            @click="selectEntry(entry)"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p
                                        class="font-schedule text-xs font-semibold"
                                    >
                                        {{
                                            entry.offering.subject.code ??
                                            entry.offering.subject.name
                                        }}
                                    </p>
                                    <p class="mt-1 truncate font-semibold">
                                        {{ entry.offering.subject.name }}
                                    </p>
                                </div>
                                <Badge :class="statusClass(entry.status)">{{
                                    statusLabel(entry.status)
                                }}</Badge>
                            </div>
                            <div
                                class="grid gap-1 font-schedule text-xs text-muted-foreground"
                            >
                                <span
                                    >{{
                                        weekdays.find(
                                            (day) =>
                                                day.value === entry.weekday,
                                        )?.name
                                    }}
                                    · {{ formatTimeRange(entry) }}</span
                                ><span class="truncate"
                                    >{{ entry.offering.student_group.name }} ·
                                    {{
                                        resourceNames(entry) ||
                                        'Resources not assigned'
                                    }}</span
                                >
                            </div>
                        </button>
                    </div>

                    <div class="border-t border-border px-3 py-3 sm:px-4">
                        <div class="mb-2 flex items-center gap-2">
                            <Check class="size-3.5 text-available" />
                            <h3
                                class="font-schedule text-[0.625rem] font-semibold tracking-[0.15em] text-muted-foreground uppercase"
                            >
                                Accessible agenda
                            </h3>
                        </div>
                        <div
                            v-if="filteredEntries.length === 0"
                            class="border border-dashed border-border px-3 py-4 text-sm text-muted-foreground"
                        >
                            No schedule entries are in this view.
                        </div>
                        <ul v-else class="grid gap-1.5" role="list">
                            <li
                                v-for="entry in filteredEntries"
                                :key="`agenda-${entry.id}`"
                            >
                                <button
                                    type="button"
                                    class="flex w-full items-start justify-between gap-3 rounded-md border border-border bg-background px-3 py-2.5 text-left transition hover:border-schedule/50 focus-visible:ring-2 focus-visible:ring-schedule focus-visible:ring-offset-2"
                                    @click="selectEntry(entry)"
                                >
                                    <span class="min-w-0"
                                        ><span
                                            class="block truncate text-sm font-medium"
                                            >{{ entry.offering.subject.name }} ·
                                            {{
                                                entry.offering.student_group
                                                    .name
                                            }}</span
                                        ><span
                                            class="mt-1 block truncate font-schedule text-[0.6875rem] text-muted-foreground"
                                            >{{
                                                weekdays.find(
                                                    (day) =>
                                                        day.value ===
                                                        entry.weekday,
                                                )?.name
                                            }}
                                            · {{ formatTimeRange(entry) }} ·
                                            {{
                                                resourceNames(entry) ||
                                                'No assigned room or teacher'
                                            }}</span
                                        ></span
                                    ><ChevronRight
                                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                    />
                                </button>
                            </li>
                        </ul>
                    </div>
                </section>

                <aside class="grid content-start gap-3 xl:sticky xl:top-3">
                    <section
                        aria-labelledby="inspector-heading"
                        class="border border-border bg-card p-4"
                    >
                        <div
                            class="flex items-start justify-between gap-3 border-b border-border pb-3"
                        >
                            <div>
                                <p
                                    class="font-schedule text-[0.625rem] font-semibold tracking-[0.16em] text-schedule uppercase"
                                >
                                    Contextual inspector
                                </p>
                                <h2
                                    id="inspector-heading"
                                    class="mt-1 text-base font-semibold"
                                >
                                    {{
                                        selectedEntry
                                            ? selectedEntry.offering.subject
                                                  .name
                                            : 'Entry details'
                                    }}
                                </h2>
                            </div>
                            <Badge
                                v-if="selectedEntry"
                                :class="statusClass(selectedEntry.status)"
                                >{{ statusLabel(selectedEntry.status) }}</Badge
                            >
                        </div>
                        <div
                            v-if="selectedEntry !== null"
                            class="mt-3 grid gap-3 text-sm"
                        >
                            <div>
                                <p
                                    class="font-schedule text-[0.625rem] font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                                >
                                    Course
                                </p>
                                <p class="mt-1 font-medium">
                                    {{
                                        selectedEntry?.offering.subject.code ??
                                        'No subject code'
                                    }}
                                    ·
                                    {{ selectedEntry?.offering.component.name }}
                                </p>
                            </div>
                            <dl
                                class="grid grid-cols-2 gap-3 border-y border-border py-3"
                            >
                                <div>
                                    <dt
                                        class="font-schedule text-[0.625rem] font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                                    >
                                        When
                                    </dt>
                                    <dd class="mt-1 font-medium">
                                        {{
                                            weekdays.find(
                                                (day) =>
                                                    day.value ===
                                                    selectedEntry?.weekday,
                                            )?.name
                                        }}
                                    </dd>
                                    <dd
                                        class="font-schedule text-xs text-muted-foreground"
                                    >
                                        {{ selectedEntryTimeRange }}
                                    </dd>
                                </div>
                                <div>
                                    <dt
                                        class="font-schedule text-[0.625rem] font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                                    >
                                        Group
                                    </dt>
                                    <dd class="mt-1 truncate font-medium">
                                        {{
                                            selectedEntry?.offering
                                                .student_group.name
                                        }}
                                    </dd>
                                    <dd
                                        class="font-schedule text-xs text-muted-foreground"
                                    >
                                        {{
                                            selectedEntry?.offering
                                                .student_group.academic_unit
                                                .name
                                        }}
                                    </dd>
                                </div>
                            </dl>
                            <div>
                                <p
                                    class="font-schedule text-[0.625rem] font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                                >
                                    Assigned resources
                                </p>
                                <ul class="mt-2 grid gap-1" role="list">
                                    <li
                                        v-for="resource in selectedEntry?.resources ??
                                        []"
                                        :key="`${resource.role}-${resource.id}`"
                                        class="flex items-center justify-between gap-2 border border-border bg-muted/35 px-2.5 py-2 text-xs"
                                    >
                                        <span class="truncate">{{
                                            resource.name
                                        }}</span
                                        ><span
                                            class="shrink-0 font-schedule text-[0.625rem] text-muted-foreground uppercase"
                                            >{{ resource.role }}</span
                                        >
                                    </li>
                                    <li
                                        v-if="
                                            selectedEntry?.resources.length ===
                                            0
                                        "
                                        class="text-xs text-muted-foreground"
                                    >
                                        No resources on this dated projection.
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <p v-else class="mt-3 text-sm text-muted-foreground">
                            Select an entry from the board or agenda to inspect
                            it.
                        </p>
                    </section>

                    <SchedulingConflictList
                        v-if="validationIssues.length > 0"
                        id="inspector-conflicts"
                        :issues="validationIssues"
                        title="Conflict status"
                        description="Resolve blocking conflicts before saving this schedule entry."
                    />

                    <section
                        aria-labelledby="editor-heading"
                        class="border border-border bg-card p-4"
                    >
                        <div
                            class="flex items-start justify-between gap-3 border-b border-border pb-3"
                        >
                            <div>
                                <p
                                    class="font-schedule text-[0.625rem] font-semibold tracking-[0.16em] text-warning uppercase"
                                >
                                    Edit entry
                                </p>
                                <h2
                                    id="editor-heading"
                                    class="mt-1 text-base font-semibold"
                                >
                                    Schedule controls
                                </h2>
                            </div>
                            <Save
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                        </div>
                        <form
                            v-if="selectedEntry"
                            class="mt-3 grid gap-3"
                            @submit.prevent="saveSelectedEntry"
                        >
                            <div class="grid gap-1.5">
                                <Label for="entry-weekday">Weekday</Label>
                                <select
                                    id="entry-weekday"
                                    v-model.number="entryForm.weekday"
                                    :disabled="!canEditSelectedEntry || saving"
                                    class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm disabled:opacity-50"
                                >
                                    <option
                                        v-for="day in weekdays"
                                        :key="day.value"
                                        :value="day.value"
                                    >
                                        {{ day.name }}
                                    </option>
                                </select>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <MinuteTimeInput
                                    id="entry-start"
                                    v-model="entryForm.starts_at_minute"
                                    name="starts_at_minute"
                                    label="Start time"
                                    required
                                    :disabled="!canEditSelectedEntry || saving"
                                />
                                <MinuteTimeInput
                                    id="entry-end"
                                    v-model="entryForm.ends_at_minute"
                                    name="ends_at_minute"
                                    label="End time"
                                    required
                                    allow-end-of-day
                                    :disabled="!canEditSelectedEntry || saving"
                                />
                            </div>
                            <div>
                                <Label for="entry-notes">Notes</Label
                                ><textarea
                                    id="entry-notes"
                                    v-model="entryForm.notes"
                                    class="mt-1.5 min-h-16 w-full rounded-md border border-input bg-background px-3 py-2 text-sm transition outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="!canEditSelectedEntry || saving"
                                    maxlength="2000"
                                />
                            </div>
                            <p
                                v-if="currentView.date !== null"
                                class="text-xs text-muted-foreground"
                            >
                                Dated projections are read-only. Clear the date
                                filter to edit the recurring entry.
                            </p>
                            <p
                                v-else-if="
                                    selectedEntry?.status === 'cancelled'
                                "
                                class="text-xs text-muted-foreground"
                            >
                                Cancelled entries are read-only. Clear the date
                                filter to edit the recurring entry.
                            </p>
                            <p
                                v-else-if="!canManageScheduling"
                                class="text-xs text-muted-foreground"
                            >
                                You can inspect this entry, but your
                                organization role cannot edit schedules.
                            </p>
                            <p
                                v-else-if="!canEditVersion"
                                class="text-xs text-muted-foreground"
                            >
                                This version is read-only. Create a draft from
                                version controls to make changes.
                            </p>
                            <p
                                v-if="saveNotice"
                                class="text-xs text-available"
                                role="status"
                            >
                                {{ saveNotice }}
                            </p>
                            <p
                                v-if="updateError"
                                class="text-sm text-conflict"
                                role="alert"
                            >
                                {{ updateError }}
                            </p>
                            <Button
                                class="w-full"
                                type="submit"
                                :disabled="!canEditSelectedEntry || saving"
                                ><RefreshCw
                                    v-if="saving"
                                    class="size-4 animate-spin"
                                /><Save v-else class="size-4" />{{
                                    saving ? 'Saving' : 'Check and save'
                                }}</Button
                            >
                        </form>
                        <p v-else class="mt-3 text-sm text-muted-foreground">
                            Select an entry to edit it.
                        </p>
                    </section>

                    <section
                        v-if="selectedEntryExceptions.length > 0"
                        aria-labelledby="exceptions-heading"
                        class="border border-warning/35 bg-warning/5 p-4"
                    >
                        <div class="flex items-center gap-2">
                            <CalendarDays class="size-4 text-warning" />
                            <h2
                                id="exceptions-heading"
                                class="text-sm font-semibold"
                            >
                                Dated changes
                            </h2>
                        </div>
                        <ul class="mt-3 grid gap-2" role="list">
                            <li
                                v-for="exception in selectedEntryExceptions"
                                :key="exception.id"
                                class="border-l-2 border-warning/60 pl-3 text-sm"
                            >
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold">{{
                                        exception.date
                                    }}</span
                                    ><Badge variant="outline">{{
                                        exception.action
                                    }}</Badge>
                                </div>
                                <p class="mt-1 text-muted-foreground">
                                    {{
                                        exception.reason ??
                                        'No reason recorded.'
                                    }}
                                </p>
                            </li>
                        </ul>
                    </section>
                </aside>
            </div>
        </template>
    </div>

    <Teleport to="body">
        <div
            v-if="commandPaletteOpen"
            class="fixed inset-0 z-50 flex items-start justify-center bg-foreground/20 p-4 pt-[12vh] backdrop-blur-[2px] dark:bg-black/55"
            role="presentation"
            @click.self="closeCommandPalette"
        >
            <section
                class="w-full max-w-xl overflow-hidden rounded-lg border border-border bg-popover text-popover-foreground shadow-xl"
                role="dialog"
                aria-modal="true"
                aria-labelledby="command-palette-heading"
            >
                <div
                    class="flex items-center gap-2 border-b border-border px-3"
                >
                    <Search
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    /><input
                        ref="commandInput"
                        v-model="commandQuery"
                        class="h-12 min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                        placeholder="Search timetable commands"
                        aria-label="Search timetable commands"
                        @keydown.enter.prevent="runFirstCommand"
                        @keydown.esc.stop.prevent="closeCommandPalette"
                    /><kbd
                        class="hidden rounded border border-border bg-muted px-1.5 py-0.5 font-schedule text-[0.625rem] text-muted-foreground sm:inline"
                        >ESC</kbd
                    ><Button
                        variant="ghost"
                        size="icon"
                        class="size-8"
                        aria-label="Close command palette"
                        @click="closeCommandPalette"
                        ><X class="size-4"
                    /></Button>
                </div>
                <div class="border-b border-border px-4 py-2">
                    <h2
                        id="command-palette-heading"
                        class="font-schedule text-[0.625rem] font-semibold tracking-[0.16em] text-muted-foreground uppercase"
                    >
                        Timetable commands
                    </h2>
                </div>
                <div class="max-h-[min(22rem,60vh)] overflow-y-auto p-1.5">
                    <button
                        v-for="command in filteredCommands"
                        :key="command.label"
                        type="button"
                        class="flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-left transition hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                        @click="runPaletteCommand(command)"
                    >
                        <span
                            class="flex size-7 shrink-0 items-center justify-center rounded border border-border bg-muted font-schedule text-xs text-muted-foreground"
                            >⌘</span
                        ><span class="min-w-0 flex-1"
                            ><span class="block text-sm font-medium">{{
                                command.label
                            }}</span
                            ><span
                                class="block truncate text-xs text-muted-foreground"
                                >{{ command.description }}</span
                            ></span
                        ><kbd
                            v-if="command.shortcut"
                            class="shrink-0 font-schedule text-[0.625rem] text-muted-foreground"
                            >{{ command.shortcut }}</kbd
                        >
                    </button>
                    <p
                        v-if="filteredCommands.length === 0"
                        class="px-3 py-8 text-center text-sm text-muted-foreground"
                    >
                        No commands match “{{ commandQuery }}”.
                    </p>
                </div>
                <footer
                    class="flex items-center justify-between border-t border-border px-3 py-2 font-schedule text-[0.625rem] text-muted-foreground"
                >
                    <span>Enter to run</span><span>⌘K to toggle</span>
                </footer>
            </section>
        </div>
    </Teleport>
</template>
