<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { ArrowUpRight, CalendarDays, Check, Layers3, Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import MinuteTimeInput from '@/components/MinuteTimeInput.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import WorkspacePageHeader from '@/components/WorkspacePageHeader.vue';
import WorkspaceSectionNav from '@/components/WorkspaceSectionNav.vue';
import WorkspaceState from '@/components/WorkspaceState.vue';
import { useWorkspaceSection } from '@/composables/useWorkspaceSection';
import { setup } from '@/routes/academic';
import { store as storeCalendar } from '@/routes/academic/calendars';
import { store as storeException } from '@/routes/academic/exceptions';
import {
    dates as updateGroupDates,
    unit as assignGroupUnit,
} from '@/routes/academic/groups';
import { toggle as toggleGroupPeriod } from '@/routes/academic/groups/periods';
import { store as storePeriod } from '@/routes/academic/periods';
import { apply as applyPreset } from '@/routes/academic/presets';
import {
    archive as archiveUnit,
    move as moveUnit,
    store as storeUnit,
} from '@/routes/academic/units';
import { activate, store as storeYear } from '@/routes/academic/years';
import type { Organization } from '@/types';

type AcademicPeriod = {
    id: string;
    name: string;
    kind: string;
    kind_label: string;
    sequence: number;
    starts_on: string;
    ends_on: string;
    calendars: AcademicCalendar[];
    exceptions: CalendarException[];
};

type AcademicCalendar = {
    weekday: number;
    starts_at_minute: number;
    ends_at_minute: number;
};

type CalendarException = {
    date: string;
    kind: string;
    name: string;
    starts_at_minute: number | null;
    ends_at_minute: number | null;
};

type AcademicYear = {
    id: string;
    name: string;
    starts_on: string;
    ends_on: string;
    status: 'draft' | 'active' | 'closed';
    periods: AcademicPeriod[];
};

type Preset = {
    value: string;
    label: string;
};

type PeriodKind = {
    value: string;
    label: string;
};

type ExceptionKind = {
    value: string;
    label: string;
};

type AcademicUnit = {
    id: string;
    parent_id: string | null;
    type_code: string;
    type_name: string;
    name: string;
    code: string | null;
};

type StudentGroup = {
    id: string;
    code: string;
    name: string;
    academic_year_id: string;
    year_starts_on: string;
    year_ends_on: string;
    year_status: 'draft' | 'active' | 'closed';
    active_from: string | null;
    active_until: string | null;
    academic_unit: { id: string; name: string };
    periods: { id: string; name: string; enrolled: boolean }[];
};

type Props = {
    years: AcademicYear[];
    presets: Preset[];
    periodKinds: PeriodKind[];
    exceptionKinds: ExceptionKind[];
    unitTypes: { code: string; name: string; units_count: number }[];
    units: AcademicUnit[];
    groups: StudentGroup[];
    summary: { years: number; unit_types: number; units: number };
    canManageAcademic: boolean;
};

const props = defineProps<Props>();
const page = usePage();

const organizationSlug = computed(
    () => page.props.currentOrganization?.slug ?? '',
);

const weekdays = [
    { value: 1, label: 'Monday' },
    { value: 2, label: 'Tuesday' },
    { value: 3, label: 'Wednesday' },
    { value: 4, label: 'Thursday' },
    { value: 5, label: 'Friday' },
    { value: 6, label: 'Saturday' },
    { value: 7, label: 'Sunday' },
];

const statusLabel = (status: AcademicYear['status']) => {
    return status === 'draft'
        ? 'Draft'
        : status === 'active'
          ? 'Active'
          : 'Closed';
};

const statusClass = (status: AcademicYear['status']) => {
    return status === 'active'
        ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
        : status === 'closed'
          ? 'border-slate-400/30 bg-slate-400/10 text-slate-600 dark:text-slate-300'
          : 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300';
};

const formatDate = (date: string) =>
    new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(new Date(`${date}T00:00:00`));

const unitDepth = (unit: AcademicUnit): number => {
    let depth = 0;
    let parentId = unit.parent_id;
    const visited = new Set<string>();

    while (parentId && !visited.has(parentId)) {
        visited.add(parentId);
        const parent = props.units.find(
            (candidate) => candidate.id === parentId,
        );

        if (!parent) {
            break;
        }

        depth += 1;
        parentId = parent.parent_id;
    }

    return depth;
};

const formatMinutes = (minutes: number) => {
    const hours = Math.floor(minutes / 60)
        .toString()
        .padStart(2, '0');
    const remainder = (minutes % 60).toString().padStart(2, '0');

    return `${hours}:${remainder}`;
};

const periodCount = computed(() =>
    props.years.reduce((count, year) => count + year.periods.length, 0),
);

const { section, selectSection } = useWorkspaceSection(
    ['years', 'calendar', 'structure', 'groups'],
    'years',
    'academic:' + organizationSlug.value,
);
const selectedYearId = ref(props.years[0]?.id ?? '');
const selectedYears = computed(() =>
    props.years.filter((year) => year.id === selectedYearId.value),
);
watch(
    () => props.years.length,
    () => {
        selectedYearId.value = props.years[0]?.id ?? '';
    },
);
const sections = computed(() => [
    { value: 'years', label: 'Years & periods', count: props.years.length },
    { value: 'calendar', label: 'Teaching calendar', count: periodCount.value },
    {
        value: 'structure',
        label: 'School structure',
        count: props.units.length,
    },
    { value: 'groups', label: 'Student groups', count: props.groups.length },
]);

defineOptions({
    layout: (layoutProps: { currentOrganization?: Organization | null }) => ({
        breadcrumbs: [
            {
                title: 'Academic setup',
                href: layoutProps.currentOrganization
                    ? setup(layoutProps.currentOrganization.slug)
                    : '/',
            },
        ],
    }),
});
</script>

<template>
    <Head title="Academic setup" />

    <div
        class="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-5 p-4 sm:p-6"
    >
        <WorkspacePageHeader
            title="Academic setup"
            section="Prepare your schedule"
            description="Set your school dates, teaching hours, structure, and student groups. Work through one section at a time."
        />
        <WorkspaceSectionNav
            :sections="sections"
            :selected="section"
            label="Academic sections"
            @select="selectSection"
        />
        <div
            v-show="section === 'years' || section === 'calendar'"
            class="max-w-sm space-y-2"
        >
            <Label for="selected-year">Academic year</Label>
            <select
                id="selected-year"
                v-model="selectedYearId"
                class="h-11 w-full rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <option v-if="years.length === 0" value="">
                    No academic years yet
                </option>
                <option v-for="year in years" :key="year.id" :value="year.id">
                    {{ year.name }} · {{ statusLabel(year.status) }}
                </option>
            </select>
        </div>

        <div
            v-show="section === 'years' || section === 'calendar'"
            class="grid min-w-0 gap-6"
            :class="
                section === 'years'
                    ? 'xl:grid-cols-[minmax(0,1.35fr)_minmax(20rem,0.65fr)]'
                    : ''
            "
        >
            <section id="academic-years" class="min-w-0 scroll-mt-6 space-y-4">
                <div
                    class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
                >
                    <Heading
                        class="min-w-0"
                        variant="small"
                        :title="
                            section === 'calendar'
                                ? 'Teaching calendar'
                                : 'Years & periods'
                        "
                        description="Periods stay inside their parent year and never overlap."
                    />
                    <Badge
                        v-if="years.length > 0"
                        class="shrink-0"
                        variant="outline"
                    >
                        {{ years.length }} configured
                    </Badge>
                </div>

                <WorkspaceState
                    v-if="years.length === 0"
                    variant="empty"
                    title="No academic year yet"
                    description="Open Years & periods to create a draft academic year. Then add the periods your school uses."
                />

                <article
                    v-for="year in selectedYears"
                    :key="year.id"
                    class="min-w-0 overflow-hidden rounded-lg border border-border/70 bg-card"
                >
                    <div
                        class="flex flex-col gap-4 border-b border-border/70 px-5 py-5 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div class="flex min-w-0 items-start gap-3">
                            <div
                                class="mt-0.5 rounded-lg bg-amber-500/15 p-2 text-amber-600 dark:text-amber-300"
                            >
                                <CalendarDays class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="font-semibold break-words">
                                    {{ year.name }}
                                </h2>
                                <p
                                    class="mt-1 text-sm leading-5 text-muted-foreground"
                                >
                                    {{ formatDate(year.starts_on) }}
                                    <span class="px-1">to</span>
                                    {{ formatDate(year.ends_on) }}
                                </p>
                            </div>
                        </div>
                        <div
                            class="flex flex-wrap items-center gap-2 sm:justify-end"
                        >
                            <Badge
                                :class="statusClass(year.status)"
                                variant="outline"
                            >
                                {{ statusLabel(year.status) }}
                            </Badge>
                            <Form
                                @success="selectSection(section)"
                                v-if="
                                    canManageAcademic && year.status === 'draft'
                                "
                                v-bind="
                                    activate.form([organizationSlug, year.id])
                                "
                                #default="{ processing }"
                            >
                                <Button
                                    type="submit"
                                    size="sm"
                                    :disabled="
                                        processing || year.periods.length === 0
                                    "
                                >
                                    <Check class="h-4 w-4" /> Activate
                                </Button>
                            </Form>
                        </div>
                    </div>

                    <div class="min-w-0 space-y-4 px-5 py-5">
                        <div
                            v-if="year.periods.length === 0"
                            class="rounded-lg bg-muted/50 px-4 py-4 text-sm leading-5 text-muted-foreground"
                        >
                            Add the first period to make this year activatable.
                        </div>
                        <div v-else class="grid gap-3 md:grid-cols-2">
                            <div
                                v-for="period in year.periods"
                                :key="period.id"
                                class="relative min-w-0 rounded-lg border border-border/70 p-4"
                            >
                                <div
                                    class="mb-3 flex items-center justify-between gap-3"
                                >
                                    <span
                                        class="font-mono text-[10px] font-semibold tracking-[0.18em] text-amber-600 uppercase dark:text-amber-300"
                                    >
                                        {{
                                            String(period.sequence).padStart(
                                                2,
                                                '0',
                                            )
                                        }}
                                        / {{ period.kind_label }}
                                    </span>
                                    <Layers3
                                        class="h-4 w-4 text-muted-foreground"
                                    />
                                </div>
                                <h3 class="font-medium break-words">
                                    {{ period.name }}
                                </h3>
                                <p
                                    class="mt-1 text-xs leading-5 text-muted-foreground"
                                >
                                    {{ formatDate(period.starts_on) }} to
                                    {{ formatDate(period.ends_on) }}
                                </p>

                                <div
                                    v-show="section === 'calendar'"
                                    class="mt-4 space-y-3 border-t border-border/70 pt-3"
                                >
                                    <div
                                        class="flex items-center justify-between gap-3"
                                    >
                                        <p class="text-xs font-medium">
                                            Operating hours
                                        </p>
                                        <span
                                            v-if="period.calendars.length === 0"
                                            class="text-[11px] text-muted-foreground"
                                            >Not set</span
                                        >
                                    </div>
                                    <div
                                        v-if="period.calendars.length > 0"
                                        class="flex flex-wrap gap-1.5"
                                    >
                                        <Badge
                                            v-for="calendar in period.calendars"
                                            :key="calendar.weekday"
                                            variant="secondary"
                                            class="font-mono text-[10px]"
                                        >
                                            {{
                                                weekdays[
                                                    calendar.weekday - 1
                                                ]?.label.slice(0, 3)
                                            }}
                                            {{
                                                formatMinutes(
                                                    calendar.starts_at_minute,
                                                )
                                            }}-{{
                                                formatMinutes(
                                                    calendar.ends_at_minute,
                                                )
                                            }}
                                        </Badge>
                                    </div>
                                    <Form
                                        @success="selectSection(section)"
                                        v-if="
                                            canManageAcademic &&
                                            year.status !== 'closed'
                                        "
                                        v-bind="
                                            storeCalendar.form([
                                                organizationSlug,
                                                period.id,
                                            ])
                                        "
                                        class="grid gap-2 rounded-lg bg-muted/50 p-3"
                                        #default="{ errors, processing }"
                                    >
                                        <p
                                            class="text-[11px] leading-4 text-muted-foreground"
                                        >
                                            Choose local teaching hours for this
                                            weekday.
                                        </p>
                                        <div class="grid gap-2 sm:grid-cols-3">
                                            <select
                                                name="weekday"
                                                aria-label="Weekday"
                                                class="h-10 rounded-md border border-input bg-background px-2 text-xs text-foreground"
                                                required
                                            >
                                                <option
                                                    v-for="weekday in weekdays"
                                                    :key="weekday.value"
                                                    :value="weekday.value"
                                                >
                                                    {{ weekday.label }}
                                                </option>
                                            </select>
                                            <MinuteTimeInput
                                                name="starts_at_minute"
                                                label="Start time"
                                                required
                                            />
                                            <MinuteTimeInput
                                                name="ends_at_minute"
                                                label="End time"
                                                allow-end-of-day
                                                required
                                            />
                                        </div>
                                        <InputError
                                            :message="
                                                errors.weekday ||
                                                errors.time ||
                                                errors.starts_at_minute ||
                                                errors.ends_at_minute
                                            "
                                        />
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            size="sm"
                                            :disabled="processing"
                                        >
                                            {{
                                                processing
                                                    ? 'Saving...'
                                                    : 'Save weekday hours'
                                            }}
                                        </Button>
                                    </Form>
                                </div>

                                <div
                                    v-show="section === 'calendar'"
                                    class="mt-4 space-y-3 border-t border-border/70 pt-3"
                                >
                                    <div
                                        class="flex items-center justify-between gap-3"
                                    >
                                        <p class="text-xs font-medium">
                                            Date exceptions
                                        </p>
                                        <span
                                            v-if="
                                                period.exceptions.length === 0
                                            "
                                            class="text-[11px] text-muted-foreground"
                                            >None</span
                                        >
                                    </div>
                                    <div
                                        v-if="period.exceptions.length > 0"
                                        class="space-y-1.5"
                                    >
                                        <div
                                            v-for="exception in period.exceptions"
                                            :key="exception.date"
                                            class="flex items-start justify-between gap-2 text-xs sm:items-center"
                                        >
                                            <span class="min-w-0 break-words">{{
                                                exception.name
                                            }}</span>
                                            <span
                                                class="shrink-0 text-right font-mono text-[10px] text-muted-foreground"
                                                >{{ exception.date }} /
                                                {{ exception.kind }}</span
                                            >
                                        </div>
                                    </div>
                                    <Form
                                        @success="selectSection(section)"
                                        v-if="
                                            canManageAcademic &&
                                            year.status !== 'closed'
                                        "
                                        v-bind="
                                            storeException.form([
                                                organizationSlug,
                                                period.id,
                                            ])
                                        "
                                        class="grid gap-2 rounded-lg bg-muted/50 p-3"
                                        #default="{ errors, processing }"
                                    >
                                        <div class="grid gap-2 sm:grid-cols-2">
                                            <Input
                                                name="date"
                                                aria-label="Exception date"
                                                type="date"
                                                :min="period.starts_on"
                                                :max="period.ends_on"
                                                required
                                            />
                                            <select
                                                name="kind"
                                                aria-label="Exception type"
                                                class="h-10 rounded-md border border-input bg-background px-2 text-xs text-foreground"
                                                required
                                            >
                                                <option
                                                    v-for="kind in exceptionKinds"
                                                    :key="kind.value"
                                                    :value="kind.value"
                                                >
                                                    {{ kind.label }}
                                                </option>
                                            </select>
                                        </div>
                                        <Input
                                            name="name"
                                            aria-label="Exception name"
                                            placeholder="Holiday or special session"
                                            required
                                        />
                                        <div class="grid gap-2 sm:grid-cols-2">
                                            <MinuteTimeInput
                                                name="starts_at_minute"
                                                label="Start time"
                                            />
                                            <MinuteTimeInput
                                                name="ends_at_minute"
                                                label="End time"
                                                allow-end-of-day
                                            />
                                        </div>
                                        <InputError
                                            :message="
                                                errors.date ||
                                                errors.kind ||
                                                errors.name ||
                                                errors.time ||
                                                errors.starts_at_minute ||
                                                errors.ends_at_minute
                                            "
                                        />
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            size="sm"
                                            :disabled="processing"
                                        >
                                            {{
                                                processing
                                                    ? 'Saving...'
                                                    : 'Save date exception'
                                            }}
                                        </Button>
                                    </Form>
                                </div>
                            </div>
                        </div>

                        <Form
                            @success="selectSection(section)"
                            v-if="canManageAcademic && year.status === 'draft'"
                            v-show="section === 'years'"
                            v-bind="
                                storePeriod.form([organizationSlug, year.id])
                            "
                            class="grid gap-3 rounded-lg border border-dashed border-border p-4 md:grid-cols-2"
                            #default="{ errors, processing }"
                        >
                            <div
                                class="flex items-center gap-2 text-sm font-medium md:col-span-2"
                            >
                                <Plus class="h-4 w-4 text-amber-500" /> Add a
                                period
                            </div>
                            <div class="grid gap-2">
                                <Label :for="`period-name-${year.id}`"
                                    >Name</Label
                                >
                                <Input
                                    :id="`period-name-${year.id}`"
                                    name="name"
                                    placeholder="Term 1"
                                    required
                                />
                                <InputError :message="errors.name" />
                            </div>
                            <div class="grid gap-2">
                                <Label :for="`period-kind-${year.id}`"
                                    >Kind</Label
                                >
                                <select
                                    :id="`period-kind-${year.id}`"
                                    name="kind"
                                    class="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"
                                    required
                                >
                                    <option
                                        v-for="kind in periodKinds"
                                        :key="kind.value"
                                        :value="kind.value"
                                    >
                                        {{ kind.label }}
                                    </option>
                                </select>
                                <InputError :message="errors.kind" />
                            </div>
                            <div class="grid gap-2">
                                <Label :for="`period-sequence-${year.id}`"
                                    >Sequence</Label
                                >
                                <Input
                                    :id="`period-sequence-${year.id}`"
                                    name="sequence"
                                    type="number"
                                    min="1"
                                    :model-value="year.periods.length + 1"
                                    required
                                />
                                <InputError :message="errors.sequence" />
                            </div>
                            <div class="grid gap-2">
                                <Label :for="`period-start-${year.id}`"
                                    >Starts</Label
                                >
                                <Input
                                    :id="`period-start-${year.id}`"
                                    name="starts_on"
                                    type="date"
                                    :min="year.starts_on"
                                    :max="year.ends_on"
                                    required
                                />
                                <InputError :message="errors.starts_on" />
                            </div>
                            <div class="grid gap-2">
                                <Label :for="`period-end-${year.id}`"
                                    >Ends</Label
                                >
                                <Input
                                    :id="`period-end-${year.id}`"
                                    name="ends_on"
                                    type="date"
                                    :min="year.starts_on"
                                    :max="year.ends_on"
                                    required
                                />
                                <InputError :message="errors.ends_on" />
                            </div>
                            <div
                                class="flex items-end justify-end md:col-span-2"
                            >
                                <Button type="submit" :disabled="processing">
                                    {{
                                        processing ? 'Adding...' : 'Add period'
                                    }}
                                </Button>
                            </div>
                        </Form>
                    </div>
                </article>
            </section>

            <aside
                v-show="section === 'years'"
                class="flex min-w-0 flex-col gap-4 xl:sticky xl:top-4 xl:self-start"
            >
                <section
                    v-if="canManageAcademic"
                    class="rounded-lg border border-amber-500/25 bg-amber-500/[0.06] p-5 dark:bg-amber-500/[0.08]"
                >
                    <div class="mb-5 flex items-start justify-between gap-4">
                        <div>
                            <p
                                class="font-mono text-[10px] font-semibold tracking-[0.2em] text-amber-700 uppercase dark:text-amber-300"
                            >
                                Start here
                            </p>
                            <h2
                                class="mt-2 text-xl font-semibold tracking-tight"
                            >
                                New academic year
                            </h2>
                        </div>
                        <ArrowUpRight
                            class="h-5 w-5 text-amber-600 dark:text-amber-300"
                        />
                    </div>
                    <Form
                        @success="selectSection(section)"
                        v-bind="storeYear.form(organizationSlug)"
                        class="grid gap-3"
                        #default="{ errors, processing }"
                    >
                        <div class="grid gap-2">
                            <Label for="year-name">Name</Label>
                            <Input
                                id="year-name"
                                name="name"
                                placeholder="2026-2027"
                                required
                            />
                            <InputError :message="errors.name" />
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="year-start">Starts</Label>
                                <Input
                                    id="year-start"
                                    name="starts_on"
                                    type="date"
                                    required
                                />
                                <InputError :message="errors.starts_on" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="year-end">Ends</Label>
                                <Input
                                    id="year-end"
                                    name="ends_on"
                                    type="date"
                                    required
                                />
                                <InputError :message="errors.ends_on" />
                            </div>
                        </div>
                        <Button
                            type="submit"
                            class="mt-2"
                            :disabled="processing"
                        >
                            {{
                                processing ? 'Creating...' : 'Create draft year'
                            }}
                        </Button>
                    </Form>
                </section>
            </aside>
        </div>

        <div v-show="section === 'structure'" class="grid gap-4 lg:grid-cols-2">
            <section
                v-if="canManageAcademic"
                class="rounded-lg border border-border/70 bg-card p-5"
            >
                <div class="mb-4 flex items-start gap-3">
                    <div
                        class="rounded-lg bg-slate-900 p-2 text-amber-300 dark:bg-slate-800"
                    >
                        <Layers3 class="h-4 w-4" />
                    </div>
                    <div>
                        <h2 class="font-semibold">Institution presets</h2>
                        <p class="mt-1 text-sm leading-5 text-muted-foreground">
                            Use a familiar starting structure, then rename or
                            extend it as your institution needs.
                        </p>
                    </div>
                </div>
                <div class="grid gap-2">
                    <Form
                        @success="selectSection(section)"
                        v-for="preset in presets"
                        :key="preset.value"
                        v-bind="applyPreset.form(organizationSlug)"
                        #default="{ processing }"
                    >
                        <input
                            type="hidden"
                            name="preset"
                            :value="preset.value"
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            class="w-full justify-between"
                            :disabled="processing"
                        >
                            {{ preset.label }}
                            <ArrowUpRight
                                class="h-4 w-4 text-muted-foreground"
                            />
                        </Button>
                    </Form>
                </div>
            </section>
            <section class="rounded-lg border border-border/70 bg-card p-5">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">Structure at a glance</h2>
                        <p class="mt-1 text-sm leading-5 text-muted-foreground">
                            Your configurable hierarchy types.
                        </p>
                    </div>
                    <Layers3 class="h-5 w-5 text-amber-500" />
                </div>
                <div v-if="unitTypes.length > 0" class="flex flex-wrap gap-2">
                    <Badge
                        v-for="type in unitTypes"
                        :key="type.code"
                        variant="secondary"
                    >
                        {{ type.name }}
                        <span class="ml-1 text-muted-foreground">{{
                            type.units_count
                        }}</span>
                    </Badge>
                </div>
                <p v-else class="text-sm leading-5 text-muted-foreground">
                    Apply a preset to sketch your institution's structure.
                </p>
            </section>
        </div>
        <section
            id="academic-structure"
            v-show="section === 'structure'"
            class="scroll-mt-6 rounded-lg border border-border/70 bg-card"
        >
            <div
                class="flex flex-col gap-4 border-b border-border/70 px-5 py-5 sm:flex-row sm:items-end sm:justify-between"
            >
                <Heading
                    class="min-w-0"
                    variant="small"
                    title="Academic structure"
                    description="Add the places people belong to, such as campuses, colleges, departments, or programs."
                />
                <Badge class="shrink-0" variant="outline"
                    >{{ units.length }} units</Badge
                >
            </div>

            <div
                class="grid min-w-0 gap-6 p-5 xl:grid-cols-[minmax(0,1fr)_20rem]"
            >
                <div v-if="units.length > 0" class="min-w-0 space-y-2">
                    <div
                        v-for="unit in units"
                        :key="unit.id"
                        class="flex min-w-0 flex-col gap-3 overflow-hidden rounded-lg border border-border/70 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
                        :style="{ marginLeft: `${unitDepth(unit) * 1.25}rem` }"
                    >
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <Layers3
                                    class="h-4 w-4 shrink-0 text-amber-500"
                                />
                                <p class="truncate font-medium">
                                    {{ unit.name }}
                                </p>
                                <Badge variant="secondary">{{
                                    unit.type_name
                                }}</Badge>
                            </div>
                            <p
                                class="mt-1 pl-6 font-mono text-[11px] text-muted-foreground"
                            >
                                {{ unit.code || 'No code' }}
                            </p>
                        </div>

                        <div
                            v-if="canManageAcademic"
                            class="flex max-w-full min-w-0 flex-wrap items-center gap-2 sm:justify-end"
                        >
                            <Form
                                @success="selectSection(section)"
                                v-bind="
                                    moveUnit.form([organizationSlug, unit.id])
                                "
                                class="flex w-full flex-wrap items-center gap-2 sm:w-auto"
                                #default="{ errors, processing }"
                            >
                                <select
                                    :id="`unit-parent-${unit.id}`"
                                    name="parent_id"
                                    class="h-9 w-full rounded-md border border-input bg-background px-2 text-xs text-foreground sm:max-w-44"
                                >
                                    <option value="">Root unit</option>
                                    <template
                                        v-for="candidate in units"
                                        :key="candidate.id"
                                    >
                                        <option
                                            v-if="candidate.id !== unit.id"
                                            :value="candidate.id"
                                        >
                                            {{ candidate.name }}
                                        </option>
                                    </template>
                                </select>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    size="sm"
                                    :disabled="processing"
                                >
                                    Move
                                </Button>
                                <InputError :message="errors.parent_id" />
                            </Form>
                            <Form
                                @success="selectSection(section)"
                                v-bind="
                                    archiveUnit.form([
                                        organizationSlug,
                                        unit.id,
                                    ])
                                "
                                #default="{ processing }"
                            >
                                <Button
                                    type="submit"
                                    variant="ghost"
                                    size="sm"
                                    :disabled="processing"
                                >
                                    Archive
                                </Button>
                            </Form>
                        </div>
                    </div>
                </div>
                <div
                    v-else
                    class="rounded-lg border border-dashed border-border px-5 py-10 text-center"
                >
                    <Layers3 class="mx-auto mb-3 h-8 w-8 text-amber-500" />
                    <p class="text-sm leading-5 text-muted-foreground">
                        Apply a preset or add the first unit from the form.
                    </p>
                </div>

                <Form
                    @success="selectSection(section)"
                    v-if="canManageAcademic"
                    v-bind="storeUnit.form(organizationSlug)"
                    class="grid gap-3 rounded-lg border border-amber-500/25 bg-amber-500/[0.06] p-4 dark:bg-amber-500/[0.08]"
                    #default="{ errors, processing }"
                >
                    <div class="flex items-center gap-2 text-sm font-medium">
                        <Plus class="h-4 w-4 text-amber-500" /> Add a unit
                    </div>
                    <div class="grid gap-2">
                        <Label for="unit-type">Type</Label>
                        <select
                            id="unit-type"
                            name="type_code"
                            class="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"
                            required
                        >
                            <option value="" disabled selected>
                                Select a type
                            </option>
                            <option
                                v-for="type in unitTypes"
                                :key="type.code"
                                :value="type.code"
                            >
                                {{ type.name }}
                            </option>
                        </select>
                        <InputError :message="errors.type_code" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="unit-name">Name</Label>
                        <Input
                            id="unit-name"
                            name="name"
                            placeholder="Main campus"
                            required
                        />
                        <InputError :message="errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="unit-code">Code</Label>
                        <Input
                            id="unit-code"
                            name="code"
                            placeholder="CAMPUS-MAIN"
                        />
                        <InputError :message="errors.code" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="unit-parent">Parent</Label>
                        <select
                            id="unit-parent"
                            name="parent_id"
                            class="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"
                        >
                            <option value="">Root unit</option>
                            <option
                                v-for="unit in units"
                                :key="unit.id"
                                :value="unit.id"
                            >
                                {{ unit.name }}
                            </option>
                        </select>
                        <InputError :message="errors.parent_id" />
                    </div>
                    <Button type="submit" class="mt-1" :disabled="processing">
                        {{ processing ? 'Creating...' : 'Create unit' }}
                    </Button>
                </Form>
            </div>
        </section>

        <section
            id="student-groups"
            v-show="section === 'groups'"
            class="scroll-mt-6 rounded-lg border border-border/70 bg-card"
        >
            <div
                class="flex flex-col gap-3 border-b border-border/70 px-5 py-5 sm:flex-row sm:items-end sm:justify-between"
            >
                <Heading
                    class="min-w-0"
                    variant="small"
                    title="Student groups"
                    description="Keep group dates, unit placement, and period participation aligned to one academic year."
                />
                <Badge class="shrink-0" variant="outline"
                    >{{ groups.length }} groups</Badge
                >
            </div>

            <div v-if="groups.length > 0" class="grid gap-4 p-5 lg:grid-cols-2">
                <article
                    v-for="group in groups"
                    :key="group.id"
                    class="min-w-0 rounded-lg border border-border/70 p-4"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p
                                class="font-mono text-[10px] font-semibold tracking-[0.18em] text-amber-600 uppercase dark:text-amber-300"
                            >
                                {{ group.code }}
                            </p>
                            <h2 class="mt-1 font-semibold break-words">
                                {{ group.name }}
                            </h2>
                            <p
                                class="mt-1 text-xs leading-5 text-muted-foreground"
                            >
                                {{ group.academic_unit.name }} ·
                                {{ group.year_status }}
                            </p>
                        </div>
                        <Badge variant="secondary"
                            >{{
                                group.periods.filter(
                                    (period) => period.enrolled,
                                ).length
                            }}
                            periods</Badge
                        >
                    </div>

                    <div
                        v-if="
                            canManageAcademic && group.year_status !== 'closed'
                        "
                        class="mt-4 grid gap-3 border-t border-border/70 pt-4"
                    >
                        <Form
                            @success="selectSection(section)"
                            v-bind="
                                updateGroupDates.form([
                                    organizationSlug,
                                    group.id,
                                ])
                            "
                            class="grid gap-2 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                            #default="{ errors, processing }"
                        >
                            <div class="grid gap-2">
                                <Label :for="`group-start-${group.id}`"
                                    >Active from</Label
                                >
                                <Input
                                    :id="`group-start-${group.id}`"
                                    name="active_from"
                                    type="date"
                                    :min="group.year_starts_on"
                                    :max="group.year_ends_on"
                                    :model-value="
                                        group.active_from || undefined
                                    "
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label :for="`group-end-${group.id}`"
                                    >Active until</Label
                                >
                                <Input
                                    :id="`group-end-${group.id}`"
                                    name="active_until"
                                    type="date"
                                    :min="group.year_starts_on"
                                    :max="group.year_ends_on"
                                    :model-value="
                                        group.active_until || undefined
                                    "
                                />
                            </div>
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                :disabled="processing"
                                >Save dates</Button
                            >
                            <InputError
                                class="sm:col-span-3"
                                :message="
                                    errors.active_dates ||
                                    errors.active_from ||
                                    errors.active_until
                                "
                            />
                        </Form>

                        <Form
                            @success="selectSection(section)"
                            v-bind="
                                assignGroupUnit.form([
                                    organizationSlug,
                                    group.id,
                                ])
                            "
                            class="flex flex-wrap items-end gap-2"
                            #default="{ errors, processing }"
                        >
                            <div class="grid min-w-48 flex-1 gap-2">
                                <Label :for="`group-unit-${group.id}`"
                                    >Academic unit</Label
                                >
                                <select
                                    :id="`group-unit-${group.id}`"
                                    name="academic_unit_id"
                                    class="h-10 rounded-md border border-input bg-background px-3 text-sm text-foreground"
                                    required
                                >
                                    <option
                                        v-for="unit in units"
                                        :key="unit.id"
                                        :value="unit.id"
                                        :selected="
                                            unit.id === group.academic_unit.id
                                        "
                                    >
                                        {{ unit.name }}
                                    </option>
                                </select>
                            </div>
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                :disabled="processing"
                                >Assign unit</Button
                            >
                            <InputError :message="errors.academic_unit_id" />
                        </Form>

                        <div class="flex flex-wrap gap-2">
                            <Form
                                @success="selectSection(section)"
                                v-for="period in group.periods"
                                :key="period.id"
                                v-bind="
                                    toggleGroupPeriod.form([
                                        organizationSlug,
                                        group.id,
                                        period.id,
                                    ])
                                "
                            >
                                <Button
                                    type="submit"
                                    size="sm"
                                    :variant="
                                        period.enrolled
                                            ? 'secondary'
                                            : 'outline'
                                    "
                                >
                                    {{
                                        period.enrolled
                                            ? `Remove ${period.name}`
                                            : `Enroll ${period.name}`
                                    }}
                                </Button>
                            </Form>
                        </div>
                    </div>
                </article>
            </div>
            <div
                v-else
                class="px-5 py-10 text-center text-sm leading-5 text-muted-foreground"
            >
                Student groups appear here after they are created in the
                scheduling resources area.
            </div>
        </section>
    </div>
</template>
