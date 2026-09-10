<script setup lang="ts">
import { Form, Head, useForm, usePage, usePoll } from '@inertiajs/vue3';
import {
    ArrowRight,
    Check,
    FolderOpen,
    LoaderCircle,
    Search,
    CircleAlert,
    Download,
    FileSpreadsheet,
    Plus,
    Power,
    ScanSearch,
    Upload,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ValidationSummary from '@/components/ValidationSummary.vue';
import WorkspacePageHeader from '@/components/WorkspacePageHeader.vue';
import WorkspaceState from '@/components/WorkspaceState.vue';
import { index as templatesIndex } from '@/routes/templates';
import { store as storeExport } from '@/routes/templates/exports';
import { activate, store as storeVersion } from '@/routes/templates/versions';
import { store as storeWorkbook } from '@/routes/templates/workbooks';
import type { Organization } from '@/types';

type Worksheet = {
    name: string;
    dimensions: { columns: number; range: string; rows: number };
    merged_cell_count: number;
    merged_cells: string[];
    merged_cells_truncated: boolean;
    preview: {
        columns: string[];
        rows: {
            row: number;
            cells: {
                coordinate: string;
                is_formula: boolean;
                value: boolean | number | string | null;
            }[];
        }[];
        truncated: boolean;
    };
};

type Workbook = {
    id: string;
    name: string;
    size: number;
    scan_status: string;
    inspection: Worksheet[] | null;
    inspection_error: string | null;
};

type TemplateVersion = {
    id: string;
    version_number: number;
    worksheet: string;
    activated_at: string | null;
    source_asset: {
        id: string;
        name: string;
        scan_status: string;
        size: number;
    };
};

type Template = {
    id: string;
    name: string;
    status: string;
    versions: TemplateVersion[];
};

type TimetableVersion = {
    id: string;
    name: string;
    period_name: string;
    number: number;
    status: string;
};

type ExportRun = {
    id: string;
    purpose: 'preview' | 'export';
    status: 'pending' | 'running' | 'completed' | 'failed';
    created_at: string | null;
    completed_at: string | null;
    error: string | null;
    template_name: string;
    template_version_number: number;
    timetable_name: string;
    timetable_version_number: number;
    download_url: string | null;
};

type Mapping = {
    worksheet: string;
    timetable_area: { start_cell: string; end_cell: string };
    day_columns: { weekday: number; column: string }[];
    time_rows: { row: number; start_minute: number }[];
    schedule_cell: { format: string };
    placeholders: { key: string; cell: string }[];
    signatory_fields: {
        role: string;
        name_cell: string;
        position_cell: string;
        signature_cell: string;
    }[];
};

type Props = {
    templates: Template[];
    timetableVersions: TimetableVersion[];
    runs: ExportRun[];
    uploadedWorkbook: Workbook | null;
    placeholderCatalog: { key: string; label: string }[];
    canCreateTemplateVersions: boolean;
    schedulingGranularity: number;
};

const props = defineProps<Props>();
const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as Organization | null,
);
const organizationSlug = computed(() => organization.value?.slug ?? '');
const weekdays = [
    { value: 1, label: 'Monday' },
    { value: 2, label: 'Tuesday' },
    { value: 3, label: 'Wednesday' },
    { value: 4, label: 'Thursday' },
    { value: 5, label: 'Friday' },
    { value: 6, label: 'Saturday' },
    { value: 7, label: 'Sunday' },
];

const mapper = useForm<{
    template_id: string;
    name: string;
    file_asset_id: string;
    mapping: Mapping;
}>('excel-template-mapper', {
    template_id: '',
    name: '',
    file_asset_id: '',
    mapping: newMapping(),
});

const exportForm = useForm<{
    template_version_id: string;
    timetable_version_id: string;
    purpose: 'preview' | 'export';
}>({
    template_version_id: '',
    timetable_version_id: '',
    purpose: 'preview',
});
const selectedTimetableVersionId = ref(props.timetableVersions[0]?.id ?? '');
const section = ref<'library' | 'mapper' | 'exports'>(
    props.uploadedWorkbook ? 'mapper' : 'library',
);
const search = ref('');
const visibleTemplates = computed(() => {
    const query = search.value.trim().toLowerCase();

    return props.templates.filter(
        (template) =>
            template.name.toLowerCase().includes(query) ||
            template.versions.some((version) =>
                [version.worksheet, version.source_asset.name].some((value) =>
                    value.toLowerCase().includes(query),
                ),
            ),
    );
});

const isWorkbookClean = computed(
    () => props.uploadedWorkbook?.scan_status === 'clean',
);
const isMapperReady = computed(() => {
    const workbook = props.uploadedWorkbook;

    return (
        isWorkbookClean.value &&
        workbook !== null &&
        workbook.inspection !== null &&
        workbook.inspection_error === null
    );
});
const selectedWorksheet = computed(() =>
    props.uploadedWorkbook?.inspection?.find(
        (worksheet) => worksheet.name === mapper.mapping.worksheet,
    ),
);
const hasPublishedTimetable = computed(
    () => selectedTimetableVersion.value?.status === 'published',
);
const selectedTimetableVersion = computed(() =>
    props.timetableVersions.find(
        (version) => version.id === selectedTimetableVersionId.value,
    ),
);
const hasActiveRuns = computed(() =>
    props.runs.some(
        (run) => run.status === 'pending' || run.status === 'running',
    ),
);

const { start: startRunPolling, stop: stopRunPolling } = usePoll(
    10_000,
    {
        only: ['runs'],
    },
    {
        autoStart: false,
    },
);

watch(
    hasActiveRuns,
    (hasActiveRuns) => {
        if (hasActiveRuns) {
            startRunPolling();

            return;
        }

        stopRunPolling();
    },
    { immediate: true },
);

watch(
    () => props.uploadedWorkbook,
    (workbook) => {
        if (workbook === null) {
            return;
        }

        section.value = 'mapper';
        mapper.file_asset_id = workbook.id;

        if (
            workbook.inspection !== null &&
            !workbook.inspection.some(
                (worksheet) => worksheet.name === mapper.mapping.worksheet,
            )
        ) {
            mapper.mapping.worksheet = workbook.inspection[0]?.name ?? '';
        }
    },
    { immediate: true },
);

function newMapping(): Mapping {
    return {
        worksheet: '',
        timetable_area: { start_cell: 'B2', end_cell: 'F10' },
        day_columns: [{ weekday: 1, column: 'B' }],
        time_rows: [{ row: 3, start_minute: 480 }],
        schedule_cell: {
            format: '{{subject_code}}\n{{group_name}}\n{{rooms}}',
        },
        placeholders: [],
        signatory_fields: [],
    };
}

function addDayColumn(): void {
    const nextWeekday = weekdays.find(
        (weekday) =>
            !mapper.mapping.day_columns.some(
                (column) => column.weekday === weekday.value,
            ),
    );

    if (nextWeekday === undefined) {
        return;
    }

    mapper.mapping.day_columns.push({
        weekday: nextWeekday.value,
        column: String.fromCharCode(66 + mapper.mapping.day_columns.length),
    });
}

function addTimeRow(): void {
    const previous = mapper.mapping.time_rows.at(-1);

    mapper.mapping.time_rows.push({
        row: (previous?.row ?? 2) + 1,
        start_minute:
            (previous?.start_minute ?? 480 - props.schedulingGranularity) +
            props.schedulingGranularity,
    });
}

function addPlaceholder(): void {
    const placeholder = props.placeholderCatalog.find(
        (option) =>
            !mapper.mapping.placeholders.some(
                (mapping) => mapping.key === option.key,
            ),
    );

    if (placeholder !== undefined) {
        mapper.mapping.placeholders.push({ key: placeholder.key, cell: 'A1' });
    }
}

function addSignatory(): void {
    mapper.mapping.signatory_fields.push({
        role: 'prepared_by',
        name_cell: 'A1',
        position_cell: '',
        signature_cell: '',
    });
}

function saveVersion(): void {
    mapper.clearErrors();
    mapper.post(storeVersion.url(organizationSlug.value), {
        preserveScroll: true,
        onSuccess: () => {
            section.value = 'library';
        },
    });
}

function queueRun(
    templateVersionId: string,
    purpose: 'preview' | 'export',
): void {
    const timetableVersion = selectedTimetableVersion.value;

    if (
        timetableVersion === undefined ||
        (purpose === 'export' && timetableVersion.status !== 'published')
    ) {
        return;
    }

    exportForm.template_version_id = templateVersionId;
    exportForm.timetable_version_id = timetableVersion.id;
    exportForm.purpose = purpose;
    exportForm.clearErrors();
    exportForm.post(storeExport.url(organizationSlug.value), {
        preserveScroll: true,
        onSuccess: () => {
            section.value = 'exports';
        },
    });
}

function formatBytes(size: number): string {
    if (size < 1024) {
        return `${size} B`;
    }

    return `${(size / 1024).toFixed(1)} KB`;
}

function formatTimestamp(value: string | null): string {
    if (value === null) {
        return 'Not completed';
    }

    return new Intl.DateTimeFormat('en-PH', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function statusClass(status: string): string {
    return (
        {
            active: 'border-available/35 bg-available/10 text-available',
            clean: 'border-available/35 bg-available/10 text-available',
            quarantined: 'border-conflict/35 bg-conflict/10 text-conflict',
            completed: 'border-available/35 bg-available/10 text-available',
            draft: 'border-warning/35 bg-warning/10 text-foreground',
            failed: 'border-conflict/35 bg-conflict/10 text-conflict',
            pending: 'border-schedule/35 bg-schedule/10 text-schedule',
            preview: 'border-schedule/35 bg-schedule/10 text-schedule',
            running: 'border-warning/35 bg-warning/10 text-foreground',
        }[status] ?? 'border-border bg-muted text-muted-foreground'
    );
}

defineOptions({
    layout: (layoutProps: { currentOrganization?: Organization | null }) => ({
        breadcrumbs: [
            {
                title: 'Templates & exports',
                href: templatesIndex(
                    layoutProps.currentOrganization?.slug ?? '',
                ).url,
            },
        ],
    }),
});
</script>

<template>
    <div
        class="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-5 p-4 sm:p-6"
    >
        <Head title="Templates & exports" />
        <WorkspacePageHeader
            section="Publish & share"
            title="Templates & exports"
            description="Turn your school's workbook into a reusable timetable layout."
        >
            <template #actions>
                <Button
                    v-if="canCreateTemplateVersions"
                    @click="section = 'mapper'"
                    ><Plus /> New template</Button
                >
            </template>
        </WorkspacePageHeader>

        <nav
            class="flex flex-wrap gap-x-5 gap-y-1 border-b"
            aria-label="Template workspace sections"
        >
            <button
                v-for="item in [
                    {
                        key: 'library',
                        label: 'Template library',
                        icon: FolderOpen,
                        count: templates.length,
                    },
                    {
                        key: 'mapper',
                        label: 'Workbook mapper',
                        icon: FileSpreadsheet,
                        count: null,
                    },
                    {
                        key: 'exports',
                        label: 'Export history',
                        icon: Download,
                        count: runs.length,
                    },
                ] as const"
                :key="item.key"
                type="button"
                :aria-pressed="section === item.key"
                class="flex min-h-11 items-center gap-2 border-b-2 px-1 py-2 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-ring"
                :class="
                    section === item.key
                        ? 'border-schedule text-schedule'
                        : 'border-transparent text-muted-foreground hover:text-foreground'
                "
                @click="section = item.key"
            >
                <component :is="item.icon" class="size-4" />{{ item.label }}
                <span
                    v-if="item.count !== null"
                    class="rounded-sm bg-muted px-1.5 font-schedule text-xs tabular-nums"
                    >{{ item.count }}</span
                >
                <span
                    v-if="item.key === 'exports' && hasActiveRuns"
                    class="size-1.5 rounded-full bg-warning"
                    aria-label="Exports in progress"
                />
            </button>
        </nav>

        <div v-show="section === 'mapper'" class="min-w-0 space-y-5">
            <ol
                class="grid gap-3 rounded-lg border bg-muted/30 p-4 sm:grid-cols-3"
                aria-label="Create a template"
            >
                <li
                    v-for="(step, index) in [
                        {
                            title: 'Upload workbook',
                            description: 'Start with your school layout.',
                            done: uploadedWorkbook !== null,
                        },
                        {
                            title: 'Map the worksheet',
                            description: 'Choose where each class belongs.',
                            done: false,
                        },
                        {
                            title: 'Save & preview',
                            description: 'Check a timetable before reuse.',
                            done: false,
                        },
                    ]"
                    :key="step.title"
                    class="flex items-start gap-3"
                >
                    <span
                        class="grid size-7 shrink-0 place-items-center rounded-md border font-schedule text-xs"
                        :class="
                            step.done
                                ? 'border-available/30 bg-available/10 text-available'
                                : 'border-border bg-card text-muted-foreground'
                        "
                        ><Check v-if="step.done" class="size-4" /><span
                            v-else
                            >{{ index + 1 }}</span
                        ></span
                    >
                    <div>
                        <p class="text-sm font-medium">{{ step.title }}</p>
                        <p class="mt-1 text-xs leading-5 text-muted-foreground">
                            {{ step.description }}
                        </p>
                    </div>
                </li>
            </ol>

            <section
                v-if="canCreateTemplateVersions"
                id="upload-workbook"
                class="min-w-0 rounded-lg border bg-card p-5 sm:p-6"
            >
                <div class="flex items-start gap-3">
                    <div
                        class="grid size-10 shrink-0 place-items-center rounded-md border border-schedule/30 bg-schedule/10 text-schedule"
                    >
                        <FileSpreadsheet class="size-5" />
                    </div>
                    <div>
                        <h2 class="text-lg font-semibold">
                            Upload your workbook
                        </h2>
                        <p class="mt-1 text-sm leading-6 text-muted-foreground">
                            Choose an .xlsx file with the layout you want to
                            reuse. Your workbook stays private and is checked
                            before mapping.
                        </p>
                    </div>
                </div>
                <Form
                    v-bind="storeWorkbook.form(organizationSlug)"
                    class="mt-5 flex flex-col gap-4 sm:flex-row sm:items-end"
                    v-slot="{ errors, processing, progress }"
                >
                    <div class="grid flex-1 gap-2">
                        <Label for="template-workbook">Excel workbook</Label>
                        <Input
                            id="template-workbook"
                            name="workbook"
                            type="file"
                            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            required
                        />
                        <p
                            v-if="errors.workbook"
                            class="text-sm text-destructive"
                        >
                            {{ errors.workbook }}
                        </p>
                        <progress
                            v-if="progress"
                            class="h-2 w-full accent-primary"
                            :value="progress.percentage"
                            max="100"
                        >
                            {{ progress.percentage }}%
                        </progress>
                    </div>
                    <Button type="submit" :disabled="processing">
                        <Upload />
                        {{ processing ? 'Uploading...' : 'Upload workbook' }}
                    </Button>
                </Form>
            </section>

            <WorkspaceState
                v-else
                variant="entitlement"
                title="Template changes are unavailable"
                description="Your organization can keep existing templates readable, but this account or plan cannot create workbook versions."
            />

            <section
                v-if="uploadedWorkbook !== null"
                class="min-w-0 rounded-lg border bg-card p-5 sm:p-6"
            >
                <div
                    class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div>
                        <p
                            class="font-schedule text-xs font-semibold tracking-[0.16em] text-muted-foreground uppercase"
                        >
                            Uploaded workbook
                        </p>
                        <h2 class="mt-1 text-lg font-semibold">
                            {{ uploadedWorkbook.name }}
                        </h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ formatBytes(uploadedWorkbook.size) }} · Original
                            workbook
                        </p>
                    </div>
                    <Badge
                        variant="outline"
                        :class="statusClass(uploadedWorkbook.scan_status)"
                    >
                        {{
                            uploadedWorkbook.scan_status === 'clean'
                                ? 'Scan complete'
                                : `Scan ${uploadedWorkbook.scan_status}`
                        }}
                    </Badge>
                </div>

                <WorkspaceState
                    v-if="
                        ['failed', 'quarantined'].includes(
                            uploadedWorkbook.scan_status,
                        )
                    "
                    class="mt-5"
                    variant="error"
                    title="This workbook could not be cleared"
                    description="Upload a different workbook or ask your administrator to check the file scan. Mapping is unavailable for this file."
                />
                <WorkspaceState
                    v-else-if="!isWorkbookClean"
                    class="mt-5"
                    variant="loading"
                    title="Security scan in progress"
                    description="The workbook cannot be inspected or mapped until the security service marks it clean. Return here once the scan status changes."
                />
                <WorkspaceState
                    v-else-if="uploadedWorkbook.inspection_error !== null"
                    class="mt-5"
                    variant="error"
                    title="Workbook inspection failed"
                    :description="uploadedWorkbook.inspection_error"
                />

                <div
                    v-else-if="isMapperReady"
                    class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(20rem,0.8fr)]"
                >
                    <div class="min-w-0 rounded-md border bg-muted/15">
                        <div
                            class="flex flex-wrap gap-2 border-b p-3"
                            role="tablist"
                            aria-label="Workbook worksheets"
                        >
                            <button
                                v-for="worksheet in uploadedWorkbook.inspection ??
                                []"
                                :key="worksheet.name"
                                type="button"
                                role="tab"
                                :aria-selected="
                                    mapper.mapping.worksheet === worksheet.name
                                "
                                class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                :class="
                                    mapper.mapping.worksheet === worksheet.name
                                        ? 'bg-schedule text-schedule-foreground'
                                        : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                                "
                                @click="
                                    mapper.mapping.worksheet = worksheet.name
                                "
                            >
                                {{ worksheet.name }}
                            </button>
                        </div>
                        <div
                            v-if="selectedWorksheet"
                            class="overflow-x-auto p-4"
                        >
                            <div
                                class="mb-3 flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground"
                            >
                                <span
                                    >{{ selectedWorksheet.dimensions.range }} ·
                                    {{ selectedWorksheet.dimensions.rows }} rows
                                    ·
                                    {{ selectedWorksheet.dimensions.columns }}
                                    columns</span
                                >
                                <span
                                    >{{
                                        selectedWorksheet.merged_cell_count
                                    }}
                                    merged range{{
                                        selectedWorksheet.merged_cell_count ===
                                        1
                                            ? ''
                                            : 's'
                                    }}</span
                                >
                            </div>
                            <table
                                class="w-full min-w-[34rem] border-separate border-spacing-0 font-schedule text-xs"
                                aria-label="Workbook cell preview"
                            >
                                <thead>
                                    <tr>
                                        <th
                                            class="sticky left-0 z-10 border-r border-b bg-card p-2 text-left text-muted-foreground"
                                        >
                                            Row
                                        </th>
                                        <th
                                            v-for="column in selectedWorksheet
                                                .preview.columns"
                                            :key="column"
                                            scope="col"
                                            class="border-r border-b bg-card p-2 text-left text-muted-foreground"
                                        >
                                            {{ column }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in selectedWorksheet.preview
                                            .rows"
                                        :key="row.row"
                                    >
                                        <th
                                            scope="row"
                                            class="sticky left-0 z-10 border-r border-b bg-card p-2 text-left font-medium text-muted-foreground"
                                        >
                                            {{ row.row }}
                                        </th>
                                        <td
                                            v-for="cell in row.cells"
                                            :key="cell.coordinate"
                                            class="max-w-32 border-r border-b p-2 align-top text-foreground"
                                            :class="
                                                cell.is_formula
                                                    ? 'bg-warning/10 text-foreground'
                                                    : ''
                                            "
                                        >
                                            <span class="sr-only"
                                                >{{ cell.coordinate }}:
                                            </span>
                                            {{
                                                cell.is_formula
                                                    ? 'Formula'
                                                    : (cell.value ?? '')
                                            }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <p
                                v-if="selectedWorksheet.preview.truncated"
                                class="mt-3 text-xs leading-5 text-muted-foreground"
                            >
                                This is a compact inspection preview. Mapping
                                still accepts cells within the workbook's
                                reported dimensions.
                            </p>
                        </div>
                    </div>

                    <form class="space-y-5" @submit.prevent="saveVersion">
                        <div>
                            <p
                                class="font-schedule text-xs font-semibold tracking-[0.16em] text-muted-foreground uppercase"
                            >
                                Map a draft version
                            </p>
                            <p
                                class="mt-1 text-sm leading-6 text-muted-foreground"
                            >
                                Cell references use workbook coordinates, such
                                as
                                <code>B3</code> and <code>AA24</code>.
                            </p>
                        </div>
                        <ValidationSummary
                            :errors="mapper.errors"
                            title="Check the workbook mapping"
                        />

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="template-select">Template</Label>
                                <select
                                    id="template-select"
                                    v-model="mapper.template_id"
                                    class="h-9 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    <option value="">
                                        Create a new template
                                    </option>
                                    <option
                                        v-for="template in templates"
                                        :key="template.id"
                                        :value="template.id"
                                    >
                                        {{ template.name }}
                                    </option>
                                </select>
                            </div>
                            <div
                                v-if="mapper.template_id === ''"
                                class="grid gap-2"
                            >
                                <Label for="template-name">Template name</Label>
                                <Input
                                    id="template-name"
                                    v-model="mapper.name"
                                    required
                                    placeholder="e.g. Registrar weekly layout"
                                />
                            </div>
                            <div
                                class="grid gap-2"
                                :class="
                                    mapper.template_id === ''
                                        ? ''
                                        : 'sm:col-span-2'
                                "
                            >
                                <Label for="worksheet">Worksheet</Label>
                                <select
                                    id="worksheet"
                                    v-model="mapper.mapping.worksheet"
                                    class="h-9 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    <option
                                        v-for="worksheet in uploadedWorkbook.inspection ??
                                        []"
                                        :key="worksheet.name"
                                        :value="worksheet.name"
                                    >
                                        {{ worksheet.name }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <fieldset class="rounded-md border p-4">
                            <legend class="px-1 text-sm font-medium">
                                Timetable area
                            </legend>
                            <p
                                class="mb-3 text-xs leading-5 text-muted-foreground"
                            >
                                The rectangle that contains all mapped day
                                columns and time rows.
                            </p>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label for="start-cell">Start cell</Label
                                    ><Input
                                        id="start-cell"
                                        v-model="
                                            mapper.mapping.timetable_area
                                                .start_cell
                                        "
                                        required
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="end-cell">End cell</Label
                                    ><Input
                                        id="end-cell"
                                        v-model="
                                            mapper.mapping.timetable_area
                                                .end_cell
                                        "
                                        required
                                    />
                                </div>
                            </div>
                        </fieldset>

                        <fieldset class="rounded-md border p-4">
                            <legend class="px-1 text-sm font-medium">
                                Day columns
                            </legend>
                            <div class="mt-2 space-y-2">
                                <div
                                    v-for="(column, index) in mapper.mapping
                                        .day_columns"
                                    :key="index"
                                    class="grid grid-cols-[minmax(0,1fr)_5rem] items-end gap-2 sm:grid-cols-[minmax(0,1fr)_5rem_auto]"
                                >
                                    <div class="grid gap-2">
                                        <Label :for="`weekday-${index}`"
                                            >Weekday</Label
                                        ><select
                                            :id="`weekday-${index}`"
                                            v-model.number="column.weekday"
                                            class="h-9 rounded-md border border-input bg-transparent px-2 text-sm"
                                        >
                                            <option
                                                v-for="weekday in weekdays"
                                                :key="weekday.value"
                                                :value="weekday.value"
                                            >
                                                {{ weekday.label }}
                                            </option>
                                        </select>
                                    </div>
                                    <div class="grid gap-2">
                                        <Label :for="`column-${index}`"
                                            >Column</Label
                                        ><Input
                                            :id="`column-${index}`"
                                            v-model="column.column"
                                            maxlength="3"
                                            required
                                        />
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        :disabled="
                                            mapper.mapping.day_columns
                                                .length === 1
                                        "
                                        @click="
                                            mapper.mapping.day_columns.splice(
                                                index,
                                                1,
                                            )
                                        "
                                        >Remove</Button
                                    >
                                </div>
                            </div>
                            <Button
                                class="mt-3"
                                type="button"
                                variant="outline"
                                size="sm"
                                :disabled="
                                    mapper.mapping.day_columns.length === 7
                                "
                                @click="addDayColumn"
                                ><Plus /> Add day</Button
                            >
                        </fieldset>

                        <fieldset class="rounded-md border p-4">
                            <legend class="px-1 text-sm font-medium">
                                Time rows
                            </legend>
                            <p
                                class="mb-3 text-xs leading-5 text-muted-foreground"
                            >
                                Minutes are counted from midnight and must match
                                the organization’s
                                {{ schedulingGranularity }}-minute scheduling
                                grid.
                            </p>
                            <div class="space-y-2">
                                <div
                                    v-for="(timeRow, index) in mapper.mapping
                                        .time_rows"
                                    :key="index"
                                    class="grid grid-cols-2 items-end gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]"
                                >
                                    <div class="grid gap-2">
                                        <Label :for="`time-row-${index}`"
                                            >Worksheet row</Label
                                        ><Input
                                            :id="`time-row-${index}`"
                                            v-model.number="timeRow.row"
                                            type="number"
                                            min="1"
                                            required
                                        />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label :for="`start-minute-${index}`"
                                            >Start minute</Label
                                        ><Input
                                            :id="`start-minute-${index}`"
                                            v-model.number="
                                                timeRow.start_minute
                                            "
                                            type="number"
                                            min="0"
                                            max="1439"
                                            required
                                        />
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        :disabled="
                                            mapper.mapping.time_rows.length ===
                                            1
                                        "
                                        @click="
                                            mapper.mapping.time_rows.splice(
                                                index,
                                                1,
                                            )
                                        "
                                        >Remove</Button
                                    >
                                </div>
                            </div>
                            <Button
                                class="mt-3"
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="addTimeRow"
                                ><Plus /> Add time row</Button
                            >
                        </fieldset>

                        <div class="grid gap-2">
                            <Label for="schedule-format"
                                >Schedule cell format</Label
                            ><textarea
                                id="schedule-format"
                                v-model="mapper.mapping.schedule_cell.format"
                                rows="4"
                                class="w-full rounded-md border border-input bg-transparent px-3 py-2 font-schedule text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                required
                            />
                            <p class="text-xs leading-5 text-muted-foreground">
                                Use schedule fields such as
                                <code v-text="'{{subject_code}}'"></code>,
                                <code v-text="'{{group_name}}'"></code>,
                                <code v-text="'{{rooms}}'"></code>, and
                                <code v-text="'{{instructors}}'"></code>.
                            </p>
                        </div>

                        <fieldset class="rounded-md border p-4">
                            <legend class="px-1 text-sm font-medium">
                                Fixed placeholders
                            </legend>
                            <p
                                class="mb-3 text-xs leading-5 text-muted-foreground"
                            >
                                Place a timetable, organization, or generation
                                value outside the timetable area.
                            </p>
                            <div class="space-y-2">
                                <div
                                    v-for="(placeholder, index) in mapper
                                        .mapping.placeholders"
                                    :key="index"
                                    class="grid grid-cols-[minmax(0,1fr)_5rem] items-end gap-2 sm:grid-cols-[minmax(0,1fr)_5rem_auto]"
                                >
                                    <div class="grid gap-2">
                                        <Label :for="`placeholder-key-${index}`"
                                            >Value</Label
                                        ><select
                                            :id="`placeholder-key-${index}`"
                                            v-model="placeholder.key"
                                            class="h-9 rounded-md border border-input bg-transparent px-2 text-sm"
                                        >
                                            <option
                                                v-for="option in placeholderCatalog"
                                                :key="option.key"
                                                :value="option.key"
                                            >
                                                {{ option.label }}
                                            </option>
                                        </select>
                                    </div>
                                    <div class="grid gap-2">
                                        <Label
                                            :for="`placeholder-cell-${index}`"
                                            >Cell</Label
                                        ><Input
                                            :id="`placeholder-cell-${index}`"
                                            v-model="placeholder.cell"
                                            maxlength="10"
                                            required
                                        />
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        @click="
                                            mapper.mapping.placeholders.splice(
                                                index,
                                                1,
                                            )
                                        "
                                        >Remove</Button
                                    >
                                </div>
                            </div>
                            <Button
                                class="mt-3"
                                type="button"
                                variant="outline"
                                size="sm"
                                :disabled="
                                    mapper.mapping.placeholders.length ===
                                    placeholderCatalog.length
                                "
                                @click="addPlaceholder"
                                ><Plus /> Add placeholder</Button
                            >
                        </fieldset>

                        <fieldset class="rounded-md border p-4">
                            <legend class="px-1 text-sm font-medium">
                                Signatory slots
                            </legend>
                            <p
                                class="mb-3 text-xs leading-5 text-muted-foreground"
                            >
                                Optional named cells for an approval signatory
                                snapshot. Leave image and position cells blank
                                when unused.
                            </p>
                            <div class="space-y-3">
                                <div
                                    v-for="(signatory, index) in mapper.mapping
                                        .signatory_fields"
                                    :key="index"
                                    class="grid gap-2 rounded-md bg-muted/35 p-3 sm:grid-cols-2"
                                >
                                    <div class="grid gap-2">
                                        <Label :for="`signatory-role-${index}`"
                                            >Role key</Label
                                        ><Input
                                            :id="`signatory-role-${index}`"
                                            v-model="signatory.role"
                                            required
                                            placeholder="prepared_by"
                                        />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label :for="`signatory-name-${index}`"
                                            >Name cell</Label
                                        ><Input
                                            :id="`signatory-name-${index}`"
                                            v-model="signatory.name_cell"
                                            required
                                        />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label
                                            :for="`signatory-position-${index}`"
                                            >Position cell</Label
                                        ><Input
                                            :id="`signatory-position-${index}`"
                                            v-model="signatory.position_cell"
                                            placeholder="Optional"
                                        />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label
                                            :for="`signatory-signature-${index}`"
                                            >Signature cell</Label
                                        ><Input
                                            :id="`signatory-signature-${index}`"
                                            v-model="signatory.signature_cell"
                                            placeholder="Optional"
                                        />
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        class="justify-self-start"
                                        @click="
                                            mapper.mapping.signatory_fields.splice(
                                                index,
                                                1,
                                            )
                                        "
                                        >Remove slot</Button
                                    >
                                </div>
                            </div>
                            <Button
                                class="mt-3"
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="addSignatory"
                                ><Plus /> Add signatory slot</Button
                            >
                        </fieldset>

                        <div
                            class="flex flex-wrap items-center justify-between gap-3 border-t pt-5"
                        >
                            <p
                                class="max-w-md text-xs leading-5 text-muted-foreground"
                            >
                                Saving creates an immutable draft version.
                                Validate it with a preview before you activate
                                it for reuse.
                            </p>
                            <Button type="submit" :disabled="mapper.processing"
                                ><Check />
                                {{
                                    mapper.processing
                                        ? 'Saving...'
                                        : 'Save draft version'
                                }}</Button
                            >
                        </div>
                    </form>
                </div>
            </section>
        </div>

        <section
            v-show="section === 'library'"
            class="min-w-0 space-y-4"
            aria-label="Template library"
        >
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <h2 class="text-base font-semibold">Saved templates</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Choose a layout to preview or export your timetable.
                    </p>
                </div>
                <div
                    v-if="templates.length"
                    class="relative w-full sm:max-w-64"
                >
                    <Search
                        class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                    />
                    <Input
                        v-model="search"
                        type="search"
                        aria-label="Search templates"
                        placeholder="Find a template..."
                        class="pl-9"
                    />
                </div>
            </div>
            <div
                v-if="!canCreateTemplateVersions"
                class="flex items-start gap-3 rounded-lg border border-warning/30 bg-warning/10 p-4 text-sm"
            >
                <CircleAlert class="mt-0.5 size-4 shrink-0" />
                <div>
                    <p class="font-medium">
                        Your saved templates are still available
                    </p>
                    <p class="mt-1 leading-6 text-muted-foreground">
                        Creating templates and new exports requires template
                        access on your organization's plan.
                    </p>
                </div>
            </div>
            <section
                v-if="canCreateTemplateVersions"
                class="grid gap-3 rounded-lg border bg-card p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
            >
                <div class="grid gap-2">
                    <Label for="run-timetable-version"
                        >Preview or export timetable version</Label
                    ><select
                        id="run-timetable-version"
                        v-model="selectedTimetableVersionId"
                        class="h-9 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <option v-if="timetableVersions.length === 0" value="">
                            No timetable versions available
                        </option>
                        <option
                            v-for="version in timetableVersions"
                            :key="version.id"
                            :value="version.id"
                        >
                            {{ version.name }} · {{ version.period_name }} · v{{
                                version.number
                            }}
                            · {{ version.status }}
                        </option>
                    </select>
                </div>
                <p class="text-xs leading-5 text-muted-foreground">
                    Drafts can be previewed. Only a published version can
                    produce an export.
                </p>
            </section>
            <WorkspaceState
                v-if="templates.length === 0"
                variant="empty"
                title="Your first template starts with a workbook"
                description="Use your school's existing Excel layout. Map its timetable cells once, then reuse it each term."
            >
                <template v-if="canCreateTemplateVersions" #action
                    ><Button @click="section = 'mapper'"
                        ><Upload /> Upload workbook <ArrowRight /></Button
                ></template>
            </WorkspaceState>
            <WorkspaceState
                v-else-if="visibleTemplates.length === 0"
                variant="empty"
                title="No matching templates"
                description="Try a template name, worksheet, or workbook filename."
            >
                <template #action
                    ><Button variant="outline" @click="search = ''"
                        >Clear search</Button
                    ></template
                >
            </WorkspaceState>
            <div v-else class="space-y-4">
                <section
                    v-for="template in visibleTemplates"
                    :key="template.id"
                    class="min-w-0 overflow-hidden rounded-lg border bg-card"
                >
                    <div
                        class="flex items-center justify-between gap-3 border-b bg-muted/30 px-4 py-3"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <div
                                class="grid size-9 shrink-0 place-items-center rounded-md border border-available/25 bg-available/10 text-available"
                            >
                                <FileSpreadsheet class="size-5" />
                            </div>
                            <div class="min-w-0">
                                <h3 class="font-semibold break-words">
                                    {{ template.name }}
                                </h3>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    {{ template.versions.length }} saved
                                    version{{
                                        template.versions.length === 1
                                            ? ''
                                            : 's'
                                    }}
                                </p>
                            </div>
                        </div>
                        <Badge
                            variant="outline"
                            :class="statusClass(template.status)"
                            >{{ template.status }}</Badge
                        >
                    </div>
                    <div class="divide-y">
                        <article
                            v-for="version in template.versions"
                            :key="version.id"
                            class="min-w-0 p-4"
                        >
                            <div
                                class="flex flex-wrap items-start justify-between gap-3"
                            >
                                <div>
                                    <div class="flex items-center gap-2">
                                        <p class="font-medium">
                                            Version {{ version.version_number }}
                                        </p>
                                        <Badge
                                            v-if="version.activated_at !== null"
                                            variant="outline"
                                            class="border-available/35 bg-available/10 text-available"
                                            >Activated</Badge
                                        >
                                    </div>
                                    <p
                                        class="mt-1 text-xs break-words text-muted-foreground"
                                    >
                                        {{ version.worksheet }} ·
                                        {{ version.source_asset.name }}
                                    </p>
                                </div>
                                <Form
                                    v-if="
                                        canCreateTemplateVersions &&
                                        version.activated_at === null
                                    "
                                    v-bind="
                                        activate.form([
                                            organizationSlug,
                                            version.id,
                                        ])
                                    "
                                    v-slot="{ processing }"
                                    ><Button
                                        type="submit"
                                        size="sm"
                                        variant="outline"
                                        :disabled="processing"
                                        ><Power /> Activate</Button
                                    ></Form
                                >
                            </div>
                            <div
                                v-if="canCreateTemplateVersions"
                                class="mt-4 flex flex-wrap gap-2"
                            >
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    :disabled="
                                        exportForm.processing ||
                                        selectedTimetableVersion === undefined
                                    "
                                    @click="queueRun(version.id, 'preview')"
                                    ><ScanSearch /> Preview</Button
                                ><Button
                                    type="button"
                                    size="sm"
                                    :disabled="
                                        exportForm.processing ||
                                        !hasPublishedTimetable
                                    "
                                    :title="
                                        hasPublishedTimetable
                                            ? undefined
                                            : 'Select a published timetable version to export.'
                                    "
                                    @click="queueRun(version.id, 'export')"
                                    ><FileSpreadsheet /> Export
                                    published</Button
                                >
                            </div>
                            <p
                                v-else
                                class="mt-4 text-xs leading-5 text-muted-foreground"
                            >
                                You can review this immutable version, but
                                cannot queue a new run.
                            </p>
                        </article>
                    </div>
                </section>
            </div>
            <p
                v-if="
                    exportForm.errors.timetable_version_id ||
                    exportForm.errors.template_version_id ||
                    exportForm.errors.purpose
                "
                class="text-sm text-destructive"
            >
                <CircleAlert class="mr-1 inline size-4" />{{
                    exportForm.errors.timetable_version_id ??
                    exportForm.errors.template_version_id ??
                    exportForm.errors.purpose
                }}
            </p>
        </section>

        <section
            v-show="section === 'exports'"
            class="min-w-0 overflow-hidden rounded-lg border bg-card"
            aria-label="Export history"
        >
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4"
            >
                <div>
                    <p
                        class="font-schedule text-xs font-semibold tracking-[0.16em] text-muted-foreground uppercase"
                    >
                        RECENT FILES
                    </p>
                    <h2 class="mt-1 text-lg font-semibold">
                        Previews & exports
                    </h2>
                </div>
                <p
                    v-if="hasActiveRuns"
                    class="flex items-center gap-2 text-xs text-muted-foreground"
                    role="status"
                >
                    <LoaderCircle class="size-3.5 motion-safe:animate-spin" />
                    Updating automatically
                </p>
            </div>
            <WorkspaceState
                v-if="runs.length === 0"
                class="m-5"
                variant="empty"
                title="No exports yet"
                description="Choose a saved template and a timetable to generate your first workbook. Completed files will be available here."
                ><template #action
                    ><Button variant="outline" @click="section = 'library'"
                        >Browse templates <ArrowRight /></Button></template
            ></WorkspaceState>
            <div v-else class="divide-y">
                <article
                    v-for="run in runs"
                    :key="run.id"
                    class="flex flex-col gap-3 px-5 py-4 lg:flex-row lg:items-center lg:justify-between"
                >
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-medium">
                                {{ run.template_name }} · v{{
                                    run.template_version_number
                                }}
                            </p>
                            <Badge
                                variant="outline"
                                :class="statusClass(run.status)"
                                >{{ run.status }}</Badge
                            ><Badge
                                variant="outline"
                                :class="statusClass(run.purpose)"
                                >{{ run.purpose }}</Badge
                            >
                        </div>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ run.timetable_name }} · timetable version
                            {{ run.timetable_version_number }} · requested
                            {{ formatTimestamp(run.created_at) }}
                        </p>
                        <p
                            v-if="run.status === 'failed'"
                            class="mt-2 text-sm text-conflict"
                        >
                            {{
                                run.error ??
                                'The workbook could not be generated. Check the mapping and try again from the template library.'
                            }}
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        <span class="text-xs text-muted-foreground">{{
                            run.completed_at
                                ? formatTimestamp(run.completed_at)
                                : run.status === 'failed'
                                  ? 'Not completed'
                                  : 'In progress'
                        }}</span
                        ><Button
                            v-if="run.download_url"
                            variant="outline"
                            size="sm"
                            as-child
                            ><a :href="run.download_url"
                                ><Download /> Download</a
                            ></Button
                        >
                    </div>
                </article>
            </div>
        </section>
    </div>
</template>
