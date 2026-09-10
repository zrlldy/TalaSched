<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CalendarDays, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import RecordDirectory from '@/components/RecordDirectory.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ValidationSummary from '@/components/ValidationSummary.vue';
import { formatAcademicDate } from '@/lib/academic';
import { store } from '@/routes/academic/periods';
import { activate } from '@/routes/academic/years';
import type { AcademicYear, AcademicKindOption } from '@/types/academic';

const props = defineProps<{
    organizationSlug: string;
    year: AcademicYear;
    kinds: AcademicKindOption[];
    canManage: boolean;
}>();
const emit = defineEmits<{ saved: []; calendar: [periodId: string] }>();
const addOpen = ref(false);
const activationOpen = ref(false);
const nextSequence = computed(() => {
    const used = new Set(props.year.periods.map((period) => period.sequence));
    let sequence = 1;

    while (used.has(sequence)) {
        sequence++;
    }

    return sequence;
});
const form = useForm(
    `academic-period:${props.organizationSlug}:${props.year.id}`,
    {
        name: '',
        kind: props.kinds[0]?.value ?? '',
        sequence: nextSequence.value,
        starts_on: '',
        ends_on: '',
    },
);
const activation = useForm({});
const activationErrors = computed<Record<string, string>>(
    () => activation.errors,
);
const periodErrors = computed<Record<string, string>>(() => form.errors);
const fieldIds = {
    name: 'period-name',
    kind: 'period-kind',
    sequence: 'period-sequence',
    starts_on: 'period-start',
    ends_on: 'period-end',
    periods: 'period-start',
};
function submit(): void {
    form.submit(store([props.organizationSlug, props.year.id]), {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults({
                name: '',
                kind: props.kinds[0]?.value ?? '',
                sequence: nextSequence.value,
                starts_on: '',
                ends_on: '',
            });
            form.reset();
            addOpen.value = false;
            emit('saved');
        },
    });
}
function activateYear(): void {
    activation.submit(activate([props.organizationSlug, props.year.id]), {
        preserveScroll: true,
        onSuccess: () => {
            activationOpen.value = false;
            emit('saved');
        },
    });
}
function preventWhileSaving(event: Event): void {
    if (form.processing || activation.processing) {
        event.preventDefault();
    }
}
</script>

<template>
    <section
        class="overflow-hidden rounded-lg border border-border/70 bg-card"
        aria-label="Periods in selected year"
    >
        <header
            class="flex flex-wrap items-center justify-between gap-4 border-b border-border/70 p-5"
        >
            <div class="min-w-0">
                <h2
                    id="year-periods-heading"
                    tabindex="-1"
                    class="rounded-sm font-semibold break-words outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    Periods in {{ year.name }}
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ formatAcademicDate(year.starts_on) }} to
                    {{ formatAcademicDate(year.ends_on) }}
                </p>
            </div>
            <div
                v-if="canManage && year.status === 'draft'"
                class="flex flex-wrap gap-2"
            >
                <Dialog v-model:open="addOpen">
                    <DialogTrigger as-child
                        ><Button type="button" variant="outline"
                            ><Plus class="size-4" />Add period</Button
                        ></DialogTrigger
                    >
                    <DialogContent
                        class="max-h-[90dvh] overflow-y-auto sm:max-w-xl"
                        :show-close-button="!form.processing"
                        @interact-outside="preventWhileSaving"
                        @escape-key-down="preventWhileSaving"
                    >
                        <DialogHeader
                            ><DialogTitle>Add a period</DialogTitle
                            ><DialogDescription
                                >{{ year.name }} · Keep dates within this year
                                and separate from existing
                                periods.</DialogDescription
                            ></DialogHeader
                        >
                        <form class="space-y-5" @submit.prevent="submit">
                            <ValidationSummary
                                :errors="periodErrors"
                                title="Check the period details"
                                :field-ids="fieldIds"
                                :field-labels="{
                                    name: 'Period name',
                                    kind: 'Period type',
                                    sequence: 'Sequence',
                                    starts_on: 'Start date',
                                    ends_on: 'End date',
                                    periods: 'Period dates',
                                }"
                            />
                            <fieldset
                                :disabled="form.processing"
                                class="grid gap-4 sm:grid-cols-2"
                            >
                                <div class="grid gap-1.5 sm:col-span-2">
                                    <Label for="period-name">Period name</Label
                                    ><Input
                                        id="period-name"
                                        v-model="form.name"
                                        placeholder="First semester"
                                        required
                                        maxlength="255"
                                        :aria-invalid="
                                            Boolean(form.errors.name)
                                        "
                                        aria-describedby="period-name-error"
                                    /><InputError
                                        id="period-name-error"
                                        :message="form.errors.name"
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="period-kind">Period type</Label
                                    ><select
                                        id="period-kind"
                                        v-model="form.kind"
                                        required
                                        class="h-10 min-w-0 rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        :aria-invalid="
                                            Boolean(form.errors.kind)
                                        "
                                        aria-describedby="period-kind-error"
                                    >
                                        <option
                                            v-for="kind in kinds"
                                            :key="kind.value"
                                            :value="kind.value"
                                        >
                                            {{ kind.label }}
                                        </option></select
                                    ><InputError
                                        id="period-kind-error"
                                        :message="form.errors.kind"
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="period-sequence">Sequence</Label
                                    ><Input
                                        id="period-sequence"
                                        v-model="form.sequence"
                                        type="number"
                                        min="1"
                                        step="1"
                                        required
                                        :aria-invalid="
                                            Boolean(form.errors.sequence)
                                        "
                                        aria-describedby="period-sequence-error period-sequence-help"
                                    /><InputError
                                        id="period-sequence-error"
                                        :message="form.errors.sequence"
                                    />
                                    <p
                                        id="period-sequence-help"
                                        class="text-xs text-muted-foreground"
                                    >
                                        Use 1, 2, 3 in order. Next available:
                                        {{ nextSequence }}.
                                    </p>
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="period-start">Start date</Label
                                    ><Input
                                        id="period-start"
                                        v-model="form.starts_on"
                                        type="date"
                                        required
                                        :aria-invalid="
                                            Boolean(
                                                form.errors.starts_on ||
                                                periodErrors.periods,
                                            )
                                        "
                                        aria-describedby="period-start-error"
                                    /><InputError
                                        id="period-start-error"
                                        :message="
                                            form.errors.starts_on ||
                                            periodErrors.periods
                                        "
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="period-end">End date</Label
                                    ><Input
                                        id="period-end"
                                        v-model="form.ends_on"
                                        type="date"
                                        required
                                        :aria-invalid="
                                            Boolean(form.errors.ends_on)
                                        "
                                        aria-describedby="period-end-error"
                                    /><InputError
                                        id="period-end-error"
                                        :message="form.errors.ends_on"
                                    />
                                </div>
                            </fieldset>
                            <p class="text-xs text-muted-foreground">
                                Year dates:
                                {{ formatAcademicDate(year.starts_on) }} to
                                {{ formatAcademicDate(year.ends_on) }}.
                            </p>
                            <DialogFooter class="gap-2"
                                ><Button
                                    type="button"
                                    variant="outline"
                                    :disabled="form.processing"
                                    @click="addOpen = false"
                                    >Close</Button
                                ><Button
                                    type="submit"
                                    :disabled="form.processing"
                                    >{{
                                        form.processing
                                            ? 'Adding period...'
                                            : 'Create period'
                                    }}</Button
                                ></DialogFooter
                            >
                            <p class="text-xs text-muted-foreground">
                                Closing keeps this year's period draft.
                            </p>
                        </form>
                    </DialogContent>
                </Dialog>
                <Dialog v-model:open="activationOpen">
                    <DialogTrigger as-child
                        ><Button
                            type="button"
                            :disabled="year.periods.length === 0"
                            aria-describedby="year-status-help"
                            >Activate year</Button
                        ></DialogTrigger
                    >
                    <DialogContent
                        class="max-h-[90dvh] overflow-y-auto sm:max-w-lg"
                        :show-close-button="!activation.processing"
                        @interact-outside="preventWhileSaving"
                        @escape-key-down="preventWhileSaving"
                    >
                        <DialogHeader
                            ><DialogTitle>Activate {{ year.name }}?</DialogTitle
                            ><DialogDescription
                                >Review all periods before activating. You
                                cannot add more periods after activation.
                                Teaching hours and date exceptions remain
                                editable.</DialogDescription
                            ></DialogHeader
                        >
                        <ValidationSummary
                            :errors="activationErrors"
                            title="This year could not be activated"
                            :field-labels="{
                                periods: 'Period sequence',
                                academic_year: 'Academic year',
                            }"
                        />
                        <ol class="divide-y rounded-md border text-sm">
                            <li
                                v-for="period in year.periods"
                                :key="period.id"
                                class="flex gap-3 p-3"
                            >
                                <span class="font-mono text-muted-foreground"
                                    >{{ period.sequence }}.</span
                                >
                                <div class="min-w-0">
                                    <p class="font-medium break-words">
                                        {{ period.name }}
                                    </p>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {{
                                            formatAcademicDate(period.starts_on)
                                        }}
                                        to
                                        {{ formatAcademicDate(period.ends_on) }}
                                    </p>
                                </div>
                            </li>
                        </ol>
                        <p
                            v-if="activation.hasErrors"
                            class="text-sm text-muted-foreground"
                        >
                            Close this dialog to review the periods and other
                            active years. Missing sequence numbers can be filled
                            by adding a period with that sequence.
                        </p>
                        <DialogFooter class="gap-2"
                            ><Button
                                type="button"
                                variant="outline"
                                :disabled="activation.processing"
                                @click="activationOpen = false"
                                >Close</Button
                            ><Button
                                type="button"
                                :disabled="activation.processing"
                                @click="activateYear"
                                >{{
                                    activation.processing
                                        ? 'Activating...'
                                        : 'Confirm activation'
                                }}</Button
                            ></DialogFooter
                        >
                    </DialogContent>
                </Dialog>
            </div>
        </header>
        <p
            id="year-status-help"
            class="border-b border-border/70 bg-muted/30 px-5 py-3 text-sm text-muted-foreground"
        >
            {{
                !canManage
                    ? 'View only. Ask an academic administrator to change this year.'
                    : year.status === 'closed'
                      ? 'This academic year is closed. Its periods and calendar are read only.'
                      : year.status === 'active'
                        ? 'This year is active. Periods are fixed; teaching hours and date exceptions can still be updated.'
                        : year.periods.length === 0
                          ? 'Add at least one period before activating this year.'
                          : 'Add every period before activating this year. Sequence numbers must start at 1 with no gaps.'
            }}
        </p>
        <RecordDirectory
            :records="year.periods"
            :columns="[
                { key: 'period', label: 'Period' },
                { key: 'dates', label: 'Dates' },
                { key: 'calendar', label: 'Teaching calendar' },
            ]"
            label="Academic periods"
            empty="No periods yet. Add the terms, semesters, or quarters used by your school."
            row-test="academic-period-row"
        >
            <template #period="{ record }"
                ><p class="font-medium">
                    {{ record.sequence }}. {{ record.name }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ record.kind_label }}
                </p></template
            >
            <template #dates="{ record }"
                ><span class="text-xs tabular-nums"
                    >{{ formatAcademicDate(record.starts_on) }} to
                    {{ formatAcademicDate(record.ends_on) }}</span
                ></template
            >
            <template #calendar="{ record }"
                ><Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    :aria-label="`View calendar for ${record.name}`"
                    @click="emit('calendar', record.id)"
                    ><CalendarDays class="size-4" />{{
                        record.calendars.length
                            ? `${record.calendars.length} teaching days`
                            : canManage && year.status !== 'closed'
                              ? 'Set teaching hours'
                              : 'View calendar'
                    }}</Button
                ></template
            >
        </RecordDirectory>
    </section>
</template>
