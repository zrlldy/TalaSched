<script setup lang="ts">
import { useRemember } from '@inertiajs/vue3';
import { computed, ref, unref } from 'vue';
import AddStudentGroupModal from '@/components/AddStudentGroupModal.vue';
import ManageStudentGroupModal from '@/components/ManageStudentGroupModal.vue';
import RecordDirectory from '@/components/RecordDirectory.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import WorkspaceState from '@/components/WorkspaceState.vue';
import type {
    AcademicUnitOption,
    AcademicYearOption,
    StudentGroup,
} from '@/types/academic';

const props = defineProps<{
    organizationSlug: string;
    groups: StudentGroup[];
    years: AcademicYearOption[];
    units: AcademicUnitOption[];
    canManage: boolean;
}>();
const emit = defineEmits<{ navigate: [section: string]; saved: [] }>();
const filters = unref(
    useRemember(
        { search: '', year: '', unit: '' },
        `group-directory:${props.organizationSlug}`,
    ),
);
const canCreate = computed(
    () =>
        props.years.some((year) => year.status !== 'closed') &&
        props.units.length > 0,
);
const yearNames = computed(
    () => new Map(props.years.map((year) => [year.id, year.name])),
);
const filteredGroups = computed(() =>
    props.groups.filter(
        (group) =>
            (!filters.year || group.academic_year_id === filters.year) &&
            (!filters.unit || group.academic_unit.id === filters.unit) &&
            `${group.code} ${group.name} ${group.academic_unit.name}`
                .toLowerCase()
                .includes(filters.search.trim().toLowerCase()),
    ),
);
const selectedId = ref<string | null>(null);
const selectedGroup = computed(() =>
    props.groups.find((group) => group.id === selectedId.value),
);
const manageOpen = ref(false);
const lastTrigger = ref<HTMLElement | null>(null);
function openGroup(group: StudentGroup, event: MouseEvent): void {
    lastTrigger.value =
        event.currentTarget instanceof HTMLElement ? event.currentTarget : null;
    selectedId.value = group.id;
    manageOpen.value = true;
}
function restoreFocus(): void {
    if (lastTrigger.value?.isConnected) {
        lastTrigger.value.focus();
    } else {
        document.getElementById('student-group-search')?.focus();
    }
}
function clearFilters(): void {
    filters.search = '';
    filters.year = '';
    filters.unit = '';
}
function created(): void {
    clearFilters();
    emit('saved');
}
const selectClass =
    'h-10 w-full rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';
</script>

<template>
    <section class="min-w-0 space-y-4" aria-label="Student groups">
        <div class="rounded-lg border border-border/70 bg-card">
            <div
                class="flex flex-wrap items-center justify-between gap-4 border-b border-border/70 px-5 py-4"
            >
                <div>
                    <h2 class="font-semibold">Student group directory</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Find a class or section, then manage its dates, school
                        unit, and participating periods.
                    </p>
                </div>
                <AddStudentGroupModal
                    v-if="canManage && canCreate"
                    :organization-slug="organizationSlug"
                    :years="years"
                    :units="units"
                    @created="created"
                />
            </div>
            <div
                class="grid grid-cols-2 items-end gap-3 p-4 xl:grid-cols-[minmax(0,1.5fr)_1fr_1fr_auto]"
            >
                <div class="col-span-2 grid gap-1.5 xl:col-span-1">
                    <Label for="student-group-search">Search groups</Label
                    ><Input
                        id="student-group-search"
                        v-model="filters.search"
                        type="search"
                        placeholder="Name, code, or school unit"
                        class="h-10"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label for="student-group-year-filter">Academic year</Label
                    ><select
                        id="student-group-year-filter"
                        v-model="filters.year"
                        :class="selectClass"
                    >
                        <option value="">All academic years</option>
                        <option
                            v-for="year in years"
                            :key="year.id"
                            :value="year.id"
                        >
                            {{ year.name }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="student-group-unit-filter">School unit</Label
                    ><select
                        id="student-group-unit-filter"
                        v-model="filters.unit"
                        :class="selectClass"
                    >
                        <option value="">All school units</option>
                        <option
                            v-for="unit in units"
                            :key="unit.id"
                            :value="unit.id"
                        >
                            {{ unit.name }}
                        </option>
                    </select>
                </div>
                <Button
                    v-if="filters.search || filters.year || filters.unit"
                    type="button"
                    variant="ghost"
                    class="col-span-2 xl:col-span-1"
                    @click="clearFilters"
                    >Clear filters</Button
                >
            </div>
            <RecordDirectory
                :records="filteredGroups"
                :columns="[
                    { key: 'group', label: 'Group' },
                    { key: 'unit', label: 'School unit' },
                    { key: 'year', label: 'Academic year' },
                    { key: 'periods', label: 'Participation' },
                    { key: 'actions', label: 'Details' },
                ]"
                label="Student group directory"
                row-test="student-group-row"
                :empty="
                    groups.length
                        ? 'No student groups match these filters. Try another search or clear the filters.'
                        : 'No student groups yet. Create a class or section of students who follow the same timetable.'
                "
            >
                <template #group="{ record }"
                    ><p class="font-medium">{{ record.name }}</p>
                    <p class="mt-1 font-mono text-xs text-muted-foreground">
                        {{ record.code }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ record.expected_headcount }} expected students
                    </p></template
                >
                <template #unit="{ record }">{{
                    record.academic_unit.name
                }}</template>
                <template #year="{ record }"
                    ><p>{{ yearNames.get(record.academic_year_id) }}</p>
                    <Badge class="mt-1 capitalize" variant="outline">{{
                        record.year_status
                    }}</Badge></template
                >
                <template #periods="{ record }"
                    ><p
                        :class="
                            record.periods.some((period) => period.enrolled)
                                ? 'text-available'
                                : 'text-muted-foreground'
                        "
                    >
                        {{
                            record.periods.filter((period) => period.enrolled)
                                .length
                        }}
                        enrolled
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{
                            record.periods
                                .filter((period) => period.enrolled)
                                .map((period) => period.name)
                                .join(', ') || 'Not enrolled in any periods'
                        }}
                    </p></template
                >
                <template #actions="{ record }"
                    ><Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :aria-label="`${canManage && record.year_status !== 'closed' ? 'Manage' : 'View'} ${record.name}`"
                        @click="openGroup(record, $event)"
                        >{{
                            canManage && record.year_status !== 'closed'
                                ? 'Manage group'
                                : 'View group'
                        }}</Button
                    ></template
                >
            </RecordDirectory>
        </div>
        <WorkspaceState
            v-if="canManage && !canCreate"
            variant="empty"
            title="Prepare a year and school structure first"
            description="Each student group belongs to an open academic year and a school unit, such as a grade level or program."
        >
            <template #action
                ><Button
                    type="button"
                    variant="outline"
                    @click="
                        emit(
                            'navigate',
                            years.some((year) => year.status !== 'closed')
                                ? 'structure'
                                : 'years',
                        )
                    "
                    >{{
                        years.some((year) => year.status !== 'closed')
                            ? 'Set up school structure'
                            : 'Set up an academic year'
                    }}</Button
                ></template
            >
        </WorkspaceState>
        <ManageStudentGroupModal
            v-if="selectedGroup"
            :key="selectedGroup.id"
            v-model:open="manageOpen"
            :organization-slug="organizationSlug"
            :group="selectedGroup"
            :year-name="yearNames.get(selectedGroup.academic_year_id) ?? ''"
            :units="units"
            :can-manage="canManage"
            @saved="emit('saved')"
            @closed="restoreFocus"
        />
    </section>
</template>
