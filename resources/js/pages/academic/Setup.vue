<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Layers3, Plus } from '@lucide/vue';
import { computed } from 'vue';
import AcademicYearWorkspace from '@/components/AcademicYearWorkspace.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import StudentGroupDirectory from '@/components/StudentGroupDirectory.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import WorkspacePageHeader from '@/components/WorkspacePageHeader.vue';
import WorkspaceSectionNav from '@/components/WorkspaceSectionNav.vue';
import { useWorkspaceSection } from '@/composables/useWorkspaceSection';
import { setup } from '@/routes/academic';
import { apply as applyPreset } from '@/routes/academic/presets';
import {
    archive as archiveUnit,
    move as moveUnit,
    store as storeUnit,
} from '@/routes/academic/units';
import type { Organization } from '@/types';
import type { AcademicYear, StudentGroup } from '@/types/academic';

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

const periodCount = computed(() =>
    props.years.reduce((count, year) => count + year.periods.length, 0),
);

const { section, selectSection } = useWorkspaceSection(
    ['years', 'calendar', 'structure', 'groups'],
    'years',
    'academic:' + organizationSlug.value,
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
        <AcademicYearWorkspace
            v-show="section === 'years' || section === 'calendar'"
            :organization-slug="organizationSlug"
            :years="years"
            :period-kinds="periodKinds"
            :exception-kinds="exceptionKinds"
            :can-manage="canManageAcademic"
            :section="section"
            :timezone="
                page.props.organizationTimezone ?? 'Organization local time'
            "
            @navigate="selectSection"
            @saved="selectSection(section)"
        />

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

        <StudentGroupDirectory
            id="student-groups"
            v-show="section === 'groups'"
            :organization-slug="organizationSlug"
            :groups="groups"
            :years="years"
            :units="units"
            :can-manage="canManageAcademic"
            @navigate="selectSection"
            @saved="selectSection('groups')"
        />
    </div>
</template>
