<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import MinuteTimeInput from '@/components/MinuteTimeInput.vue';
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
import { academicWeekdays, formatAcademicDate } from '@/lib/academic';
import { store as storeHours } from '@/routes/academic/calendars';
import { store as storeException } from '@/routes/academic/exceptions';
import type {
    AcademicPeriod,
    AcademicKindOption,
    CalendarException,
} from '@/types/academic';

const props = defineProps<{
    open: boolean;
    organizationSlug: string;
    period: AcademicPeriod;
    timezone: string;
    weekday?: number;
    exception?: CalendarException;
    kinds: AcademicKindOption[];
}>();
const emit = defineEmits<{
    'update:open': [open: boolean];
    saved: [];
    closed: [];
}>();
const isHours = computed(() => props.weekday !== undefined);
const savedHours = props.period.calendars.find(
    (day) => day.weekday === props.weekday,
);
type CalendarForm = {
    weekday: number;
    date: string;
    kind: string;
    name: string;
    starts_at_minute: number | '';
    ends_at_minute: number | '';
};
const form = useForm<CalendarForm>(
    `calendar:${props.organizationSlug}:${props.period.id}:${props.weekday !== undefined ? `weekday-${props.weekday}` : `exception-${props.exception?.date ?? 'new'}`}`,
    {
        weekday: props.weekday ?? 1,
        date: props.exception?.date ?? '',
        kind: props.exception?.kind ?? props.kinds[0]?.value ?? 'holiday',
        name: props.exception?.name ?? '',
        starts_at_minute:
            (isHours.value
                ? savedHours?.starts_at_minute
                : props.exception?.starts_at_minute) ?? '',
        ends_at_minute:
            (isHours.value
                ? savedHours?.ends_at_minute
                : props.exception?.ends_at_minute) ?? '',
    },
);
const errors = computed<Record<string, string>>(() => form.errors);
const showTimes = computed(() => isHours.value || form.kind === 'teaching');
const replacedException = computed(() =>
    props.period.exceptions.find((exception) => exception.date === form.date),
);
const title = computed(() =>
    isHours.value
        ? `${academicWeekdays[(props.weekday ?? 1) - 1]} teaching hours`
        : props.exception
          ? 'Edit date exception'
          : 'Add a date exception',
);
function changeOpen(open: boolean): void {
    if (!form.processing) {
        emit('update:open', open);
    }
}
function preventWhileSaving(event: Event): void {
    if (form.processing) {
        event.preventDefault();
    }
}
function submit(): void {
    form.transform((data) =>
        isHours.value
            ? {
                  weekday: data.weekday,
                  starts_at_minute: data.starts_at_minute,
                  ends_at_minute: data.ends_at_minute,
              }
            : {
                  date: data.date,
                  name: data.name,
                  kind: data.kind,
                  starts_at_minute: showTimes.value
                      ? data.starts_at_minute
                      : null,
                  ends_at_minute: showTimes.value ? data.ends_at_minute : null,
              },
    ).submit(
        (isHours.value ? storeHours : storeException)([
            props.organizationSlug,
            props.period.id,
        ]),
        {
            preserveScroll: true,
            onSuccess: () => {
                if (isHours.value || props.exception) {
                    form.defaults();
                } else {
                    form.defaults({
                        weekday: 1,
                        date: '',
                        kind: props.kinds[0]?.value ?? 'holiday',
                        name: '',
                        starts_at_minute: '',
                        ends_at_minute: '',
                    });
                    form.reset();
                }

                emit('update:open', false);
                emit('saved');
            },
        },
    );
}
</script>

<template>
    <Dialog :open="open" @update:open="changeOpen">
        <DialogContent
            class="max-h-[90dvh] overflow-y-auto sm:max-w-xl"
            :show-close-button="!form.processing"
            @interact-outside="preventWhileSaving"
            @escape-key-down="preventWhileSaving"
            @close-auto-focus="
                (event) => {
                    event.preventDefault();
                    emit('closed');
                }
            "
        >
            <DialogHeader
                ><DialogTitle>{{ title }}</DialogTitle
                ><DialogDescription
                    >{{ period.name }} · {{ timezone }}<br />{{
                        formatAcademicDate(period.starts_on)
                    }}
                    to
                    {{ formatAcademicDate(period.ends_on) }}</DialogDescription
                ></DialogHeader
            >
            <form class="space-y-5" @submit.prevent="submit">
                <ValidationSummary
                    :errors="errors"
                    title="Check the calendar details"
                    :field-ids="{
                        date: 'exception-date',
                        kind: 'exception-kind',
                        name: 'exception-name',
                        starts_at_minute: 'calendar-start',
                        ends_at_minute: 'calendar-end',
                        time: 'calendar-start',
                    }"
                    :field-labels="{
                        date: 'Exception date',
                        kind: 'Exception type',
                        name: 'Exception name',
                        starts_at_minute: 'Start time',
                        ends_at_minute: 'End time',
                        time: 'Teaching hours',
                    }"
                />
                <fieldset :disabled="form.processing" class="grid gap-4">
                    <template v-if="!isHours">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="exception-date"
                                    >Exception date</Label
                                ><Input
                                    id="exception-date"
                                    v-model="form.date"
                                    type="date"
                                    :readonly="Boolean(exception)"
                                    required
                                    :aria-invalid="Boolean(errors.date)"
                                    aria-describedby="exception-date-error"
                                /><InputError
                                    id="exception-date-error"
                                    :message="errors.date"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="exception-kind"
                                    >Exception type</Label
                                ><select
                                    id="exception-kind"
                                    v-model="form.kind"
                                    class="h-10 min-w-0 rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    :aria-invalid="Boolean(errors.kind)"
                                    aria-describedby="exception-kind-error"
                                >
                                    <option
                                        v-for="kind in kinds"
                                        :key="kind.value"
                                        :value="kind.value"
                                    >
                                        {{ kind.label }}
                                    </option></select
                                ><InputError
                                    id="exception-kind-error"
                                    :message="errors.kind"
                                />
                            </div>
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="exception-name">Exception name</Label
                            ><Input
                                id="exception-name"
                                v-model="form.name"
                                placeholder="Holiday or special teaching day"
                                maxlength="255"
                                required
                                :aria-invalid="Boolean(errors.name)"
                                aria-describedby="exception-name-error"
                            /><InputError
                                id="exception-name-error"
                                :message="errors.name"
                            />
                        </div>
                        <p
                            v-if="replacedException"
                            role="status"
                            class="rounded-md border border-warning/30 bg-warning/10 p-3 text-sm"
                        >
                            Saving replaces the exception for
                            {{ formatAcademicDate(form.date) }}:
                            {{ replacedException.name }}.
                        </p>
                    </template>
                    <p class="text-sm text-muted-foreground">
                        {{
                            isHours
                                ? 'Set the time window when classes may be scheduled on this weekday.'
                                : showTimes
                                  ? 'Leave both times blank to use the normal weekday hours, or enter both for a special teaching window.'
                                  : 'Holiday and blocked dates cover the full day. No classes may be scheduled.'
                        }}
                    </p>
                    <div v-if="showTimes" class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1.5">
                            <MinuteTimeInput
                                id="calendar-start"
                                v-model="form.starts_at_minute"
                                name="starts_at_minute"
                                label="Start time"
                                :required="isHours"
                                :disabled="form.processing"
                                :aria-invalid="
                                    Boolean(
                                        errors.starts_at_minute || errors.time,
                                    )
                                "
                                aria-describedby="calendar-start-error"
                            /><InputError
                                id="calendar-start-error"
                                :message="
                                    errors.starts_at_minute || errors.time
                                "
                            />
                        </div>
                        <div class="space-y-1.5">
                            <MinuteTimeInput
                                id="calendar-end"
                                v-model="form.ends_at_minute"
                                name="ends_at_minute"
                                label="End time"
                                :required="isHours"
                                :disabled="form.processing"
                                allow-end-of-day
                                :aria-invalid="Boolean(errors.ends_at_minute)"
                                aria-describedby="calendar-end-error"
                            /><InputError
                                id="calendar-end-error"
                                :message="errors.ends_at_minute"
                            />
                        </div>
                    </div>
                </fieldset>
                <DialogFooter class="gap-2"
                    ><Button
                        type="button"
                        variant="outline"
                        :disabled="form.processing"
                        @click="changeOpen(false)"
                        >Close</Button
                    ><Button type="submit" :disabled="form.processing">{{
                        form.processing
                            ? 'Saving...'
                            : isHours
                              ? 'Save weekday hours'
                              : replacedException
                                ? 'Replace date exception'
                                : 'Save date exception'
                    }}</Button></DialogFooter
                >
                <p class="text-xs text-muted-foreground">
                    Closing keeps your draft for this period.
                </p>
            </form>
        </DialogContent>
    </Dialog>
</template>
