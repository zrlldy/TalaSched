<script setup lang="ts">
import { Head, useHttp, usePage } from '@inertiajs/vue3';
import {
    CalendarDays,
    Check,
    ChevronRight,
    Clock3,
    Filter,
    RefreshCw,
    Save,
    SlidersHorizontal,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import SchedulingConflictList from '@/components/scheduling/SchedulingConflictList.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import WorkspacePageHeader from '@/components/WorkspacePageHeader.vue';
import WorkspaceState from '@/components/WorkspaceState.vue';
import { dashboard } from '@/routes';
import { update, validate } from '@/routes/scheduling/entries';
import { views } from '@/routes/scheduling/timetables';
import type {
    ScheduleIssue,
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
};
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
    resources: Option[];
    units: Option[];
    canManageScheduling: boolean;
};
type ViewResponse = {
    data: { type: string; attributes: TimetableView };
};
type EntryPayload = {
    lock_version: number;
    weekday: number;
    starts_at_minute: number;
    ends_at_minute: number;
    delivery_mode: string;
    notes: string | null;
    resources: { resource_id: string; role: string }[];
};
type ValidationPayload = EntryPayload & {
    timetable_version_id: string;
    offering_component_id: string;
};
type ValidationResponse = {
    data: {
        attributes: {
            valid: boolean;
            issues: ScheduleIssue[];
            score: number;
        };
    };
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
const validationScore = ref<number | null>(null);

const viewHttp = useHttp<Record<string, never>, ViewResponse>({});
const entryHttp = useHttp<EntryPayload, { data: unknown }>({
    lock_version: 1,
    weekday: 1,
    starts_at_minute: 480,
    ends_at_minute: 570,
    delivery_mode: 'physical',
    notes: null,
    resources: [],
});
const validationHttp = useHttp<ValidationPayload, ValidationResponse>({
    timetable_version_id: '',
    offering_component_id: '',
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
    () => page.props.currentOrganization?.slug ?? currentView.value.context.organization.slug,
);
const selectedEntry = computed(() =>
    currentView.value.entries.find((entry) => entry.id === selectedEntryId.value) ?? null,
);
const selectedEntryExceptions = computed(
    () => selectedEntry.value?.exceptions ?? [],
);
const canEditSelectedEntry = computed(
    () =>
        props.canManageScheduling &&
        currentView.value.date === null &&
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

    return Math.max(420, times.length > 0 ? Math.floor(Math.min(...times) / 60) * 60 : 420);
});
const gridEnd = computed(() => {
    const times = filteredEntries.value
        .map((entry) => entry.ends_at_minute)
        .filter((minute): minute is number => minute !== null);

    return Math.min(1320, times.length > 0 ? Math.ceil(Math.max(...times) / 60) * 60 : 1140);
});
const pixelsPerMinute = 1.1;
const gridHeight = computed(() => Math.max(520, (gridEnd.value - gridStart.value) * pixelsPerMinute));
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

const formatMinutes = (minutes: number | null): string => {
    if (minutes === null) {
        return 'No time';
    }

    const hours = Math.floor(minutes / 60);
    const remainder = minutes % 60;
    const suffix = hours >= 12 ? 'PM' : 'AM';
    const hour = hours % 12 || 12;

    return `${hour}:${remainder.toString().padStart(2, '0')} ${suffix}`;
};
const formatTimeRange = (entry: TimetableViewEntry): string =>
    `${formatMinutes(entry.starts_at_minute)} - ${formatMinutes(entry.ends_at_minute)}`;
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
        .filter((resource) => resource.role === 'room' || resource.role === 'instructor')
        .map((resource) => resource.name)
        .join(' / ');
const optionLabel = (option: Option): string =>
    option.code ? `${option.code} / ${option.name}` : option.name;

const syncEditorFromEntry = (entry: TimetableViewEntry | null): void => {
    if (entry === null) {
        validationIssues.value = [];
        validationScore.value = null;

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
    Object.assign(entryHttp, payload);
    Object.assign(validationHttp, {
        ...payload,
        timetable_version_id: currentView.value.context.version.id,
        offering_component_id: entry.offering.component.id,
    });
    validationIssues.value = [];
    validationScore.value = null;
    updateError.value = null;
};

watch(selectedEntry, syncEditorFromEntry, { immediate: true });
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

    window.history.replaceState(window.history.state, '', `${window.location.pathname}?${query.toString()}`);
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
        loadError.value = error instanceof Error ? error.message : 'The timetable view could not be loaded.';
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
const scopeChanged = (): void => {
    selectedResourceId.value = null;
    selectedUnitId.value = null;
};
const selectEntry = (entry: TimetableViewEntry): void => {
    selectedEntryId.value = entry.id;
};

const validateSelectedEntry = async (): Promise<boolean> => {
    const entry = selectedEntry.value;

    if (entry === null || entry.status === 'cancelled') {
        return false;
    }

    validationIssues.value = [];
    validationScore.value = null;

    try {
        const response = await validationHttp.post(
            validate.url(organizationSlug.value),
        );
        validationIssues.value = response.data.attributes.issues;
        validationScore.value = response.data.attributes.score;

        return response.data.attributes.valid;
    } catch (error) {
        updateError.value = error instanceof Error ? error.message : 'The entry could not be validated.';

        return false;
    }
};
const saveSelectedEntry = async (): Promise<void> => {
    const entry = selectedEntry.value;

    if (entry === null || !canEditSelectedEntry.value) {
        return;
    }

    updateError.value = null;
    const isValid = await validateSelectedEntry();

    if (!isValid) {
        return;
    }

    try {
        await entryHttp.patch(update.url([organizationSlug.value, entry.id]));
        await refreshView();
    } catch (error) {
        updateError.value = error instanceof Error ? error.message : 'The entry could not be saved.';
    }
};

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

    <div class="flex flex-col gap-6 pb-8">
        <WorkspacePageHeader
            section="Scheduling / Timetable"
            :title="currentView.context.timetable.name"
            description="One recurring schedule, many useful views. Filter the board by the people, groups, rooms, or academic units that need it."
        >
            <template #status>
                <Badge variant="outline">Version {{ currentView.context.version.number }} · {{ currentView.context.version.status }}</Badge>
            </template>
            <template #metadata>
                <span>{{ currentView.context.period.name }}</span>
                <span>{{ currentView.context.organization.timezone }}</span>
                <span class="text-schedule">{{ currentView.entries.length }} entries in view</span>
            </template>
            <template #actions>
                <Button variant="outline" size="sm" type="button" @click="resetFilters">
                    <RefreshCw class="size-4" /> Reset view
                </Button>
            </template>
        </WorkspacePageHeader>

        <section aria-labelledby="filter-heading" class="rounded-xl border border-slate-200 bg-card p-4 shadow-sm dark:border-slate-800 sm:p-5">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                <div class="flex items-start gap-3">
                    <div class="rounded-lg bg-schedule/10 p-2 text-schedule" aria-hidden="true"><SlidersHorizontal class="size-4" /></div>
                    <div>
                        <h2 id="filter-heading" class="font-semibold">Shape the view</h2>
                        <p class="mt-1 text-sm text-muted-foreground">Filters are kept in the URL so a useful view can be shared.</p>
                    </div>
                </div>
                <form class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:flex xl:flex-wrap" @submit.prevent="refreshView">
                    <div class="min-w-40">
                        <Label for="view-scope">View by</Label>
                        <select id="view-scope" v-model="selectedScope" class="mt-1.5 h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]" @change="scopeChanged">
                            <option v-for="scope in scopeOptions" :key="scope.value" :value="scope.value">{{ scope.label }}</option>
                        </select>
                    </div>
                    <div v-if="selectedResourceOptions.length > 0" class="min-w-48">
                        <Label for="view-resource">Resource</Label>
                        <select id="view-resource" v-model="selectedResourceId" class="mt-1.5 h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]">
                            <option :value="null">Choose a resource</option>
                            <option v-for="resource in selectedResourceOptions" :key="resource.id" :value="resource.id">{{ optionLabel(resource) }}</option>
                        </select>
                    </div>
                    <div v-if="selectedScope === 'unit'" class="min-w-48">
                        <Label for="view-unit">Academic unit</Label>
                        <select id="view-unit" v-model="selectedUnitId" class="mt-1.5 h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]">
                            <option :value="null">Choose a unit</option>
                            <option v-for="unit in units" :key="unit.id" :value="unit.id">{{ optionLabel(unit) }}</option>
                        </select>
                    </div>
                    <div class="min-w-40">
                        <Label for="view-version">Version</Label>
                        <select id="view-version" v-model="selectedVersionId" class="mt-1.5 h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]">
                            <option :value="null">Published or approved</option>
                            <option v-for="version in versions" :key="version.id" :value="version.id">v{{ version.number }} · {{ version.status }}</option>
                        </select>
                    </div>
                    <div class="min-w-44">
                        <Label for="view-date">Specific date</Label>
                        <Input id="view-date" v-model="selectedDate" class="mt-1.5" type="date" :min="currentView.context.period.starts_on" :max="currentView.context.period.ends_on" />
                    </div>
                    <Button class="self-end" type="submit" :disabled="viewHttp.processing">
                        <Filter class="size-4" /> {{ viewHttp.processing ? 'Loading' : 'Apply filters' }}
                    </Button>
                </form>
            </div>
        </section>

        <WorkspaceState v-if="loadError" variant="error" title="The timetable view could not be loaded" :description="loadError">
            <template #action><Button variant="outline" type="button" @click="refreshView">Try again</Button></template>
        </WorkspaceState>

        <template v-else>
            <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_20rem]">
                <section aria-labelledby="board-heading" class="min-w-0 overflow-hidden rounded-xl border border-slate-200 bg-card shadow-sm dark:border-slate-800">
                    <div class="flex flex-col gap-3 border-b border-border px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                        <div>
                            <p class="font-schedule text-[0.6875rem] font-semibold tracking-[0.16em] text-schedule uppercase">Schedule board</p>
                            <h2 id="board-heading" class="mt-1 text-lg font-semibold">{{ hasSelectedDate ? selectedDateLabel : 'Weekly rhythm' }}</h2>
                        </div>
                        <div class="flex items-center gap-2 text-xs text-muted-foreground">
                            <Clock3 class="size-4 text-schedule" />
                            <span>{{ formatMinutes(gridStart) }} to {{ formatMinutes(gridEnd) }}</span>
                        </div>
                    </div>

                    <div class="hidden overflow-x-auto lg:block">
                        <div class="min-w-[58rem]">
                            <div class="grid grid-cols-[4.5rem_repeat(7,minmax(8.5rem,1fr))] border-b border-border bg-slate-50/80 dark:bg-slate-950/30" role="row">
                                <div class="border-r border-border p-3 font-schedule text-[0.625rem] font-semibold tracking-[0.15em] text-muted-foreground uppercase" role="columnheader">Time</div>
                                <div v-for="day in weekdays" :key="day.value" class="border-r border-border px-3 py-3 last:border-r-0" role="columnheader">
                                    <span class="font-schedule text-[0.625rem] font-semibold tracking-[0.15em] text-muted-foreground uppercase">{{ day.short }}</span>
                                    <span class="mt-1 block text-sm font-semibold">{{ day.name }}</span>
                                </div>
                            </div>
                            <div class="grid grid-cols-[4.5rem_repeat(7,minmax(8.5rem,1fr))]" role="rowgroup">
                                <div class="relative border-r border-border bg-slate-50/50 dark:bg-slate-950/20" :style="{ height: `${gridHeight}px` }" aria-hidden="true">
                                    <span v-for="label in timeLabels" :key="label" class="absolute right-2 -translate-y-1/2 font-schedule text-[0.625rem] text-muted-foreground" :style="{ top: `${(label - gridStart) * pixelsPerMinute}px` }">{{ formatMinutes(label) }}</span>
                                </div>
                                <div v-for="day in weekdays" :key="day.value" class="relative border-r border-border last:border-r-0" :style="{ height: `${gridHeight}px` }" role="gridcell" :aria-label="day.name">
                                    <div v-for="label in timeLabels" :key="label" class="absolute inset-x-0 border-t border-dashed border-slate-200/80 dark:border-slate-800/80" :style="{ top: `${(label - gridStart) * pixelsPerMinute}px` }" aria-hidden="true" />
                                    <button v-for="entry in entriesForDay(day.value)" :key="entry.id" type="button" class="absolute inset-x-1.5 z-10 overflow-hidden rounded-lg border p-2 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md focus-visible:ring-2 focus-visible:ring-schedule focus-visible:ring-offset-2" :class="[entry.status === 'cancelled' ? 'border-conflict/40 bg-conflict/10' : 'border-[#dfc99d] bg-[#fff8e7] text-slate-900 dark:border-[#806c42] dark:bg-[#332b1b] dark:text-amber-50', selectedEntryId === entry.id ? 'ring-2 ring-schedule ring-offset-1' : '']" :style="entryStyle(entry)" :aria-label="`${entry.offering.subject.name}, ${formatTimeRange(entry)}, ${statusLabel(entry.status)}`" @click="selectEntry(entry)">
                                        <span class="block truncate text-xs font-bold">{{ entry.offering.subject.code ?? entry.offering.subject.name }}</span>
                                        <span class="mt-1 block truncate text-[0.6875rem]">{{ entry.offering.student_group.name }}</span>
                                        <span class="mt-1 block truncate font-schedule text-[0.625rem] opacity-70">{{ formatTimeRange(entry) }}</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-3 p-4 lg:hidden">
                        <div v-if="filteredEntries.length === 0" class="rounded-lg border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">No entries match this view.</div>
                        <button v-for="entry in filteredEntries" :key="entry.id" type="button" class="grid gap-3 rounded-lg border p-4 text-left transition focus-visible:ring-2 focus-visible:ring-schedule focus-visible:ring-offset-2" :class="[entry.status === 'cancelled' ? 'border-conflict/35 bg-conflict/5' : 'border-[#dfc99d] bg-[#fff8e7] text-slate-900 dark:border-[#806c42] dark:bg-[#332b1b] dark:text-amber-50', selectedEntryId === entry.id ? 'ring-2 ring-schedule ring-offset-1' : '']" @click="selectEntry(entry)">
                            <div class="flex items-start justify-between gap-3"><div><p class="font-schedule text-xs font-bold">{{ entry.offering.subject.code ?? entry.offering.subject.name }}</p><p class="mt-1 font-semibold">{{ entry.offering.subject.name }}</p></div><Badge :class="statusClass(entry.status)">{{ statusLabel(entry.status) }}</Badge></div>
                            <div class="grid gap-1 text-sm"><span>{{ formatTimeRange(entry) }}</span><span class="text-xs opacity-70">{{ entry.offering.student_group.name }} · {{ resourceNames(entry) || 'Resources not assigned' }}</span></div>
                        </button>
                    </div>

                    <div class="border-t border-border px-4 py-4 sm:px-5">
                        <div class="mb-3 flex items-center gap-2"><Check class="size-4 text-available" /><h3 class="font-schedule text-[0.6875rem] font-semibold tracking-[0.14em] text-muted-foreground uppercase">Accessible agenda</h3></div>
                        <div v-if="filteredEntries.length === 0" class="rounded-lg border border-dashed border-border px-4 py-5 text-sm text-muted-foreground">No schedule entries are in this view.</div>
                        <ul v-else class="grid gap-2" role="list">
                            <li v-for="entry in filteredEntries" :key="`agenda-${entry.id}`"><button type="button" class="flex w-full items-start justify-between gap-3 rounded-lg border border-border bg-background px-3 py-3 text-left transition hover:border-schedule/50 focus-visible:ring-2 focus-visible:ring-schedule focus-visible:ring-offset-2" @click="selectEntry(entry)"><span class="min-w-0"><span class="block truncate font-semibold">{{ entry.offering.subject.name }} · {{ entry.offering.student_group.name }}</span><span class="mt-1 block font-schedule text-xs text-muted-foreground">{{ weekdays.find((day) => day.value === entry.weekday)?.name }} · {{ formatTimeRange(entry) }} · {{ resourceNames(entry) || 'No assigned room or teacher' }}</span></span><ChevronRight class="mt-1 size-4 shrink-0 text-muted-foreground" /></button></li>
                        </ul>
                    </div>
                </section>

                <aside class="grid content-start gap-5">
                    <section aria-labelledby="inspector-heading" class="rounded-xl border border-slate-200 bg-card p-5 shadow-sm dark:border-slate-800">
                        <div class="flex items-start justify-between gap-3"><div><p class="font-schedule text-[0.6875rem] font-semibold tracking-[0.14em] text-schedule uppercase">Inspector</p><h2 id="inspector-heading" class="mt-1 text-lg font-semibold">Entry details</h2></div><Badge v-if="selectedEntry" :class="statusClass(selectedEntry.status)">{{ statusLabel(selectedEntry.status) }}</Badge></div>
                        <div v-if="selectedEntry" class="mt-5 grid gap-4 text-sm"><div><p class="font-schedule text-[0.625rem] font-semibold tracking-[0.14em] text-muted-foreground uppercase">Subject</p><p class="mt-1 font-semibold">{{ selectedEntry.offering.subject.name }}</p><p class="text-xs text-muted-foreground">{{ selectedEntry.offering.subject.code ?? 'No subject code' }} · {{ selectedEntry.offering.component.name }}</p></div><div class="grid grid-cols-2 gap-3"><div><p class="font-schedule text-[0.625rem] font-semibold tracking-[0.14em] text-muted-foreground uppercase">Student group</p><p class="mt-1 font-medium">{{ selectedEntry.offering.student_group.name }}</p></div><div><p class="font-schedule text-[0.625rem] font-semibold tracking-[0.14em] text-muted-foreground uppercase">Owning unit</p><p class="mt-1 font-medium">{{ selectedEntry.offering.owning_unit?.name ?? 'Not assigned' }}</p></div></div><div><p class="font-schedule text-[0.625rem] font-semibold tracking-[0.14em] text-muted-foreground uppercase">Resources</p><ul class="mt-2 grid gap-1" role="list"><li v-for="resource in selectedEntry.resources" :key="`${resource.role}-${resource.id}`" class="flex items-center justify-between gap-2 rounded-md bg-muted px-2.5 py-2 text-xs"><span>{{ resource.name }}</span><span class="font-schedule text-[0.625rem] text-muted-foreground uppercase">{{ resource.role }}</span></li><li v-if="selectedEntry.resources.length === 0" class="text-xs text-muted-foreground">No resources on this dated projection.</li></ul></div></div><p v-else class="mt-5 text-sm text-muted-foreground">Select an entry from the board or agenda to inspect it.</p>
                    </section>

                    <SchedulingConflictList v-if="validationIssues.length > 0" :issues="validationIssues" title="Entry validation" description="Resolve blocking conflicts before saving this schedule entry." />

                    <section aria-labelledby="editor-heading" class="rounded-xl border border-slate-200 bg-card p-5 shadow-sm dark:border-slate-800">
                        <div class="flex items-start justify-between gap-3"><div><p class="font-schedule text-[0.6875rem] font-semibold tracking-[0.14em] text-warning uppercase">Editor</p><h2 id="editor-heading" class="mt-1 text-lg font-semibold">Adjust this entry</h2></div><Save class="size-5 text-muted-foreground" aria-hidden="true" /></div>
                        <form v-if="selectedEntry" class="mt-5 grid gap-4" @submit.prevent="saveSelectedEntry"><div class="grid grid-cols-2 gap-3"><div><Label for="entry-start">Starts (minute)</Label><Input id="entry-start" v-model.number="entryHttp.starts_at_minute" class="mt-1.5" type="number" min="0" max="1439" step="1" :disabled="!canEditSelectedEntry" /></div><div><Label for="entry-end">Ends (minute)</Label><Input id="entry-end" v-model.number="entryHttp.ends_at_minute" class="mt-1.5" type="number" min="1" max="1440" step="1" :disabled="!canEditSelectedEntry" /></div></div><div><Label for="entry-notes">Notes</Label><textarea id="entry-notes" v-model="entryHttp.notes" class="mt-1.5 min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50" :disabled="!canEditSelectedEntry" /></div><p v-if="currentView.date !== null" class="text-xs text-muted-foreground">Dated projections are read-only. Clear the date filter to edit the recurring entry.</p><p v-else-if="selectedEntry.status === 'cancelled'" class="text-xs text-muted-foreground">Cancelled entries are read-only. Clear the date filter to edit the recurring entry.</p><p v-else-if="!canManageScheduling" class="text-xs text-muted-foreground">You can inspect this entry, but your organization role cannot edit schedules.</p><p v-if="validationScore !== null && validationIssues.length === 0" class="text-xs text-available">Validated with score {{ validationScore }}.</p><p v-if="updateError" class="text-sm text-conflict" role="alert">{{ updateError }}</p><Button type="submit" :disabled="!canEditSelectedEntry || entryHttp.processing || validationHttp.processing"><RefreshCw v-if="entryHttp.processing || validationHttp.processing" class="size-4 animate-spin" /><Save v-else class="size-4" />{{ entryHttp.processing ? 'Saving' : validationHttp.processing ? 'Checking' : 'Check and save' }}</Button></form><p v-else class="mt-5 text-sm text-muted-foreground">Select an entry to edit it.</p>
                    </section>

                    <section v-if="selectedEntryExceptions.length > 0" aria-labelledby="exceptions-heading" class="rounded-xl border border-warning/35 bg-warning/5 p-5"><div class="flex items-center gap-2"><CalendarDays class="size-4 text-warning" /><h2 id="exceptions-heading" class="font-semibold">Dated changes</h2></div><ul class="mt-4 grid gap-3" role="list"><li v-for="exception in selectedEntryExceptions" :key="exception.id" class="border-l-2 border-warning/60 pl-3 text-sm"><div class="flex flex-wrap items-center gap-2"><span class="font-semibold">{{ exception.date }}</span><Badge variant="outline">{{ exception.action }}</Badge></div><p class="mt-1 text-muted-foreground">{{ exception.reason ?? 'No reason recorded.' }}</p></li></ul></section>
                </aside>
            </div>
        </template>
    </div>
</template>
