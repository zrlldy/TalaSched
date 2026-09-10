<script setup lang="ts">
import { useRemember } from '@inertiajs/vue3';
import { computed, nextTick, unref, watch } from 'vue';
import AcademicYearPeriods from '@/components/AcademicYearPeriods.vue';
import AddAcademicYearModal from '@/components/AddAcademicYearModal.vue';
import PeriodCalendar from '@/components/PeriodCalendar.vue';
import RecordDirectory from '@/components/RecordDirectory.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import WorkspaceState from '@/components/WorkspaceState.vue';
import { formatAcademicDate } from '@/lib/academic';
import type { AcademicYear, AcademicKindOption } from '@/types/academic';

const props = defineProps<{
    organizationSlug: string;
    years: AcademicYear[];
    periodKinds: AcademicKindOption[];
    exceptionKinds: AcademicKindOption[];
    canManage: boolean;
    section: string;
    timezone: string;
}>();
const emit = defineEmits<{ navigate: [section: string]; saved: [] }>();
const state = unref(
    useRemember(
        {
            search: '',
            status: '',
            year:
                props.years.find((year) => year.status === 'active')?.id ??
                props.years[0]?.id ??
                '',
            period: '',
        },
        `academic-years:${props.organizationSlug}`,
    ),
);
const selectedYear = computed(() =>
    props.years.find((year) => year.id === state.year),
);
const selectedPeriod = computed(() =>
    selectedYear.value?.periods.find((period) => period.id === state.period),
);
const filteredYears = computed(() =>
    props.years.filter(
        (year) =>
            (!state.status || state.status === year.status) &&
            year.name.toLowerCase().includes(state.search.trim().toLowerCase()),
    ),
);
let knownYearIds = new Set(props.years.map((year) => year.id));
watch(
    () => props.years,
    (years) => {
        if (!years.some((year) => year.id === state.year)) {
            state.year = years[0]?.id ?? '';
        }
    },
);
watch(
    () => selectedYear.value?.periods,
    (periods) => {
        if (!periods?.some((period) => period.id === state.period)) {
            state.period = periods?.[0]?.id ?? '';
        }
    },
    { immediate: true },
);
function created(): void {
    state.year =
        props.years.find((year) => !knownYearIds.has(year.id))?.id ??
        state.year;
    knownYearIds = new Set(props.years.map((year) => year.id));
    clearFilters();
    emit('saved');
}
function clearFilters(): void {
    state.search = '';
    state.status = '';
}
async function selectYear(id: string): Promise<void> {
    state.year = id;
    await nextTick();
    document.getElementById('year-periods-heading')?.focus();
}
function viewCalendar(periodId: string): void {
    state.period = periodId;
    emit('navigate', 'calendar');
}
const selectClass =
    'h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';
</script>

<template>
    <div class="min-w-0 space-y-5">
        <section
            v-show="section === 'years'"
            class="overflow-hidden rounded-lg border border-border/70 bg-card"
            aria-label="Academic years"
        >
            <header
                class="flex flex-wrap items-center justify-between gap-4 border-b border-border/70 px-5 py-4"
            >
                <div>
                    <h2 class="font-semibold">Academic year directory</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Find a year, review its periods, and prepare the
                        teaching calendar.
                    </p>
                </div>
                <AddAcademicYearModal
                    v-if="canManage"
                    :organization-slug="organizationSlug"
                    @created="created"
                />
            </header>
            <div
                v-if="years.length"
                class="grid items-end gap-3 p-4 sm:grid-cols-[minmax(0,1fr)_12rem_auto]"
            >
                <div class="grid gap-1.5">
                    <Label for="academic-year-search"
                        >Find an academic year</Label
                    ><Input
                        id="academic-year-search"
                        v-model="state.search"
                        type="search"
                        placeholder="Search by year name"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label for="academic-year-status">Status</Label
                    ><select
                        id="academic-year-status"
                        v-model="state.status"
                        :class="selectClass"
                    >
                        <option value="">All statuses</option>
                        <option value="draft">Draft</option>
                        <option value="active">Active</option>
                        <option value="closed">Closed</option>
                    </select>
                </div>
                <Button
                    v-if="state.search || state.status"
                    type="button"
                    variant="ghost"
                    @click="clearFilters"
                    >Clear filters</Button
                >
            </div>
            <RecordDirectory
                :records="filteredYears"
                :columns="[
                    { key: 'year', label: 'Academic year' },
                    { key: 'dates', label: 'Dates' },
                    { key: 'status', label: 'Status' },
                    { key: 'periods', label: 'Periods' },
                ]"
                label="Academic years"
                :empty="
                    years.length
                        ? 'No academic years match your filters. Clear the filters to see all years.'
                        : canManage
                          ? 'No academic year yet. Add a draft year, then create its periods.'
                          : 'No academic year yet. Ask an academic administrator to create one.'
                "
                row-test="academic-year-row"
            >
                <template #year="{ record }"
                    ><span class="font-medium">{{ record.name }}</span
                    ><span
                        v-if="record.id === state.year"
                        class="ml-2 text-xs text-muted-foreground"
                        >Selected</span
                    ></template
                >
                <template #dates="{ record }"
                    ><span class="text-xs tabular-nums"
                        >{{ formatAcademicDate(record.starts_on) }} to
                        {{ formatAcademicDate(record.ends_on) }}</span
                    ></template
                >
                <template #status="{ record }"
                    ><Badge
                        variant="outline"
                        class="capitalize"
                        :class="
                            record.status === 'active'
                                ? 'border-available/30 bg-available/10'
                                : ''
                        "
                        >{{ record.status }}</Badge
                    ></template
                >
                <template #periods="{ record }"
                    ><Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        :aria-label="`View periods for ${record.name}`"
                        :aria-pressed="record.id === state.year"
                        @click="selectYear(record.id)"
                        >{{ record.periods.length }}
                        {{
                            record.periods.length === 1 ? 'period' : 'periods'
                        }}
                        · View</Button
                    ></template
                >
            </RecordDirectory>
        </section>
        <AcademicYearPeriods
            v-if="selectedYear"
            v-show="section === 'years'"
            :key="selectedYear.id"
            :organization-slug="organizationSlug"
            :year="selectedYear"
            :kinds="periodKinds"
            :can-manage="canManage"
            @saved="emit('saved')"
            @calendar="viewCalendar"
        />
        <section
            v-show="section === 'calendar'"
            class="min-w-0 space-y-5"
            aria-label="Teaching calendar"
        >
            <div v-if="years.length" class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="selected-year">Academic year</Label
                    ><select
                        id="selected-year"
                        v-model="state.year"
                        :class="selectClass"
                    >
                        <option
                            v-for="year in years"
                            :key="year.id"
                            :value="year.id"
                        >
                            {{ year.name }} · {{ year.status }}
                        </option>
                    </select>
                </div>
                <div v-if="selectedYear?.periods.length" class="grid gap-1.5">
                    <Label for="selected-period">Academic period</Label
                    ><select
                        id="selected-period"
                        v-model="state.period"
                        :class="selectClass"
                    >
                        <option
                            v-for="period in selectedYear.periods"
                            :key="period.id"
                            :value="period.id"
                        >
                            {{ period.name }}
                        </option>
                    </select>
                </div>
            </div>
            <WorkspaceState
                v-if="!selectedYear || !selectedPeriod"
                variant="empty"
                :title="
                    !selectedYear
                        ? 'Create an academic year first'
                        : 'Add a period first'
                "
                description="Teaching hours and date exceptions belong to an academic period. Open Years & periods to prepare the dates."
                ><template #action
                    ><Button
                        type="button"
                        variant="outline"
                        @click="emit('navigate', 'years')"
                        >Go to years & periods</Button
                    ></template
                ></WorkspaceState
            >
            <template v-else
                ><p class="text-sm text-muted-foreground">
                    {{ formatAcademicDate(selectedPeriod.starts_on) }} to
                    {{ formatAcademicDate(selectedPeriod.ends_on)
                    }}<span v-if="selectedYear.status === 'closed'">
                        · This academic year is closed.</span
                    >
                </p>
                <PeriodCalendar
                    :key="selectedPeriod.id"
                    :organization-slug="organizationSlug"
                    :period="selectedPeriod"
                    :timezone="timezone"
                    :can-edit="canManage && selectedYear.status !== 'closed'"
                    :kinds="exceptionKinds"
                    @saved="emit('saved')"
            /></template>
        </section>
    </div>
</template>
