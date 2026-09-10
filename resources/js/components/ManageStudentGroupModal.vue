<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ValidationSummary from '@/components/ValidationSummary.vue';
import WorkspaceSectionNav from '@/components/WorkspaceSectionNav.vue';
import { dates, unit as assignUnit } from '@/routes/academic/groups';
import { toggle } from '@/routes/academic/groups/periods';
import type { AcademicUnitOption, StudentGroup } from '@/types/academic';

const props = defineProps<{
    open: boolean;
    organizationSlug: string;
    group: StudentGroup;
    units: AcademicUnitOption[];
    yearName: string;
    canManage: boolean;
}>();
const emit = defineEmits<{
    'update:open': [open: boolean];
    saved: [];
    closed: [];
}>();
const panel = ref('periods');
const formKey = `student-group:${props.organizationSlug}:${props.group.id}`;
const dateForm = useForm(`${formKey}:dates`, {
    active_from: props.group.active_from ?? '',
    active_until: props.group.active_until ?? '',
});
const unitForm = useForm(`${formKey}:unit`, {
    academic_unit_id: props.group.academic_unit.id,
});
const periodForm = useForm({});
const dateErrors = computed<Record<string, string>>(() => dateForm.errors);
const periodErrors = computed<Record<string, string>>(() => periodForm.errors);
const pendingPeriodId = ref<string | null>(null);
const periodName = ref('');
const canEdit = computed(
    () => props.canManage && props.group.year_status !== 'closed',
);
const busy = computed(
    () => dateForm.processing || unitForm.processing || periodForm.processing,
);
function changeOpen(open: boolean): void {
    if (!busy.value) {
        emit('update:open', open);
    }
}
function saveDates(): void {
    if (busy.value || !canEdit.value) {
        return;
    }

    dateForm.submit(dates([props.organizationSlug, props.group.id]), {
        preserveScroll: true,
        onSuccess: () => {
            dateForm.defaults();
            emit('saved');
        },
    });
}
function saveUnit(): void {
    if (busy.value || !canEdit.value) {
        return;
    }

    unitForm.submit(assignUnit([props.organizationSlug, props.group.id]), {
        preserveScroll: true,
        onSuccess: () => {
            unitForm.defaults();
            emit('saved');
        },
    });
}
function togglePeriod(period: StudentGroup['periods'][number]): void {
    if (busy.value || !canEdit.value) {
        return;
    }

    periodForm.clearErrors();
    periodName.value = period.name;
    pendingPeriodId.value = period.id;
    periodForm.submit(
        toggle([props.organizationSlug, props.group.id, period.id]),
        {
            preserveScroll: true,
            onSuccess: () => emit('saved'),
            onFinish: () => {
                pendingPeriodId.value = null;
            },
        },
    );
}
</script>

<template>
    <Dialog :open="open" @update:open="changeOpen">
        <DialogContent
            class="max-h-[90dvh] overflow-y-auto sm:max-w-2xl"
            :show-close-button="!busy"
            @close-auto-focus="
                (event) => {
                    event.preventDefault();
                    emit('closed');
                }
            "
            @interact-outside="
                (event) => {
                    if (busy) event.preventDefault();
                }
            "
            @escape-key-down="
                (event) => {
                    if (busy) event.preventDefault();
                }
            "
        >
            <DialogHeader>
                <DialogTitle>{{ group.name }}</DialogTitle>
                <DialogDescription
                    >{{ group.code }} / {{ yearName }} /
                    {{ group.expected_headcount }} expected
                    students</DialogDescription
                >
            </DialogHeader>
            <p
                v-if="!canEdit"
                role="status"
                class="rounded-md border bg-muted/30 p-3 text-sm text-muted-foreground"
            >
                {{
                    group.year_status === 'closed'
                        ? 'This academic year is closed. Group details and participation are read only.'
                        : 'You can view this group. Ask an academic administrator to change its details or periods.'
                }}
            </p>
            <WorkspaceSectionNav
                :sections="[
                    { value: 'periods', label: 'Participating periods' },
                    { value: 'details', label: 'Dates & unit' },
                ]"
                :selected="panel"
                label="Student group details"
                @select="panel = $event"
            />

            <section
                v-show="panel === 'periods'"
                class="space-y-4"
                aria-label="Participating periods"
            >
                <p class="text-sm text-muted-foreground">
                    {{
                        canEdit
                            ? 'Enroll this group in the periods it attends. The saved active dates must overlap each participating period.'
                            : 'These are the periods this group participates in.'
                    }}
                </p>
                <ValidationSummary
                    :errors="periodErrors"
                    :title="`Check participation for ${periodName}`"
                    :field-labels="{
                        active_dates: 'Active dates',
                        academic_period: 'Academic period',
                        academic_year: 'Academic year',
                    }"
                />
                <Button
                    v-if="
                        (periodErrors.active_dates ||
                            periodErrors.academic_period) &&
                        canEdit
                    "
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="panel = 'details'"
                    >Review active dates</Button
                >
                <div
                    v-if="group.periods.length"
                    class="divide-y rounded-lg border"
                >
                    <div
                        v-for="period in group.periods"
                        :key="period.id"
                        class="flex flex-wrap items-center justify-between gap-3 p-4"
                    >
                        <div class="min-w-0">
                            <p class="font-medium break-words">
                                {{ period.name }}
                            </p>
                            <p
                                class="mt-1 text-xs"
                                :class="
                                    period.enrolled
                                        ? 'text-available'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{
                                    period.enrolled
                                        ? 'Enrolled'
                                        : 'Not enrolled'
                                }}
                            </p>
                        </div>
                        <Button
                            v-if="canEdit"
                            type="button"
                            size="sm"
                            :variant="period.enrolled ? 'secondary' : 'outline'"
                            :disabled="busy"
                            :aria-label="`${period.enrolled ? 'Remove' : 'Enroll'} ${period.name}`"
                            @click="togglePeriod(period)"
                            >{{
                                pendingPeriodId === period.id
                                    ? 'Saving...'
                                    : period.enrolled
                                      ? 'Remove'
                                      : 'Enroll'
                            }}</Button
                        >
                        <Badge v-else variant="outline">{{
                            period.enrolled ? 'Enrolled' : 'Not enrolled'
                        }}</Badge>
                    </div>
                </div>
                <p
                    v-else
                    class="rounded-md border border-dashed p-4 text-sm text-muted-foreground"
                >
                    No periods are available in this academic year. Add a period
                    in Years & periods before enrolling this group.
                </p>
                <p
                    v-if="periodForm.recentlySuccessful"
                    role="status"
                    class="text-sm text-available"
                >
                    Participation saved.
                </p>
            </section>

            <section
                v-show="panel === 'details'"
                class="space-y-5"
                aria-label="Group dates and unit"
            >
                <template v-if="canEdit">
                    <form @submit.prevent="saveDates" class="space-y-3">
                        <h3 class="font-medium">Active dates</h3>
                        <p class="text-sm text-muted-foreground">
                            Leave either date blank to use that boundary of the
                            academic year: {{ group.year_starts_on }} to
                            {{ group.year_ends_on }}.
                        </p>
                        <ValidationSummary
                            :errors="dateErrors"
                            title="Check the active dates"
                            :field-labels="{
                                active_dates: 'Active dates',
                                active_from: 'Active from',
                                active_until: 'Active until',
                            }"
                            :field-ids="{
                                active_dates: 'group-dates',
                                active_from: 'group-active-from',
                                active_until: 'group-active-until',
                            }"
                        />
                        <fieldset
                            id="group-dates"
                            tabindex="-1"
                            :disabled="busy"
                            class="grid min-w-0 gap-3 sm:grid-cols-2"
                        >
                            <div class="grid gap-1.5">
                                <Label for="group-active-from"
                                    >Active from</Label
                                ><Input
                                    id="group-active-from"
                                    v-model="dateForm.active_from"
                                    type="date"
                                    :min="group.year_starts_on"
                                    :max="group.year_ends_on"
                                    :aria-invalid="
                                        Boolean(
                                            dateErrors.active_from ||
                                            dateErrors.active_dates,
                                        )
                                    "
                                    aria-describedby="group-active-from-error"
                                /><InputError
                                    id="group-active-from-error"
                                    :message="dateErrors.active_from"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="group-active-until"
                                    >Active until</Label
                                ><Input
                                    id="group-active-until"
                                    v-model="dateForm.active_until"
                                    type="date"
                                    :min="group.year_starts_on"
                                    :max="group.year_ends_on"
                                    :aria-invalid="
                                        Boolean(
                                            dateErrors.active_until ||
                                            dateErrors.active_dates,
                                        )
                                    "
                                    aria-describedby="group-active-until-error"
                                /><InputError
                                    id="group-active-until-error"
                                    :message="dateErrors.active_until"
                                />
                            </div>
                        </fieldset>
                        <div class="flex flex-wrap items-center gap-3">
                            <Button
                                type="submit"
                                variant="outline"
                                :disabled="busy"
                                >{{
                                    dateForm.processing
                                        ? 'Saving dates...'
                                        : 'Save dates'
                                }}</Button
                            ><span
                                v-if="dateForm.recentlySuccessful"
                                role="status"
                                class="text-sm text-available"
                                >Dates saved.</span
                            >
                        </div>
                    </form>
                    <form
                        @submit.prevent="saveUnit"
                        class="space-y-3 border-t pt-5"
                    >
                        <h3 class="font-medium">School unit</h3>
                        <ValidationSummary
                            :errors="unitForm.errors"
                            title="Check the school unit"
                            :field-labels="{ academic_unit_id: 'School unit' }"
                            :field-ids="{
                                academic_unit_id: 'group-assigned-unit',
                            }"
                        />
                        <Label for="group-assigned-unit" class="sr-only"
                            >School unit</Label
                        ><select
                            id="group-assigned-unit"
                            v-model="unitForm.academic_unit_id"
                            :disabled="busy"
                            required
                            :aria-invalid="
                                Boolean(unitForm.errors.academic_unit_id)
                            "
                            aria-describedby="group-assigned-unit-error"
                            class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <option
                                v-for="unit in units"
                                :key="unit.id"
                                :value="unit.id"
                            >
                                {{ unit.name }} / {{ unit.type_name }}
                            </option></select
                        ><InputError
                            id="group-assigned-unit-error"
                            :message="unitForm.errors.academic_unit_id"
                        />
                        <div class="flex flex-wrap items-center gap-3">
                            <Button
                                type="submit"
                                variant="outline"
                                :disabled="busy"
                                >{{
                                    unitForm.processing
                                        ? 'Assigning...'
                                        : 'Assign unit'
                                }}</Button
                            ><span
                                v-if="unitForm.recentlySuccessful"
                                role="status"
                                class="text-sm text-available"
                                >School unit saved.</span
                            >
                        </div>
                    </form>
                </template>
                <dl v-else class="grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-muted-foreground">School unit</dt>
                        <dd class="mt-1 font-medium">
                            {{ group.academic_unit.name }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Active dates</dt>
                        <dd class="mt-1">
                            {{ group.active_from || group.year_starts_on }} to
                            {{ group.active_until || group.year_ends_on }}
                        </dd>
                    </div>
                </dl>
            </section>
            <DialogFooter
                ><Button
                    type="button"
                    variant="outline"
                    :disabled="busy"
                    @click="changeOpen(false)"
                    >Close</Button
                ></DialogFooter
            >
        </DialogContent>
    </Dialog>
</template>
