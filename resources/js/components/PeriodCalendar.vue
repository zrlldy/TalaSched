<script setup lang="ts">
import { Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import CalendarEntryModal from '@/components/CalendarEntryModal.vue';
import RecordDirectory from '@/components/RecordDirectory.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    academicWeekdays,
    formatAcademicDate,
    formatAcademicTime,
} from '@/lib/academic';
import type {
    AcademicPeriod,
    AcademicKindOption,
    CalendarException,
} from '@/types/academic';

const props = defineProps<{
    organizationSlug: string;
    period: AcademicPeriod;
    timezone: string;
    canEdit: boolean;
    kinds: AcademicKindOption[];
}>();
const emit = defineEmits<{ saved: [] }>();
const search = ref('');
const exceptions = computed(() =>
    props.period.exceptions
        .filter((exception) =>
            `${exception.name} ${exception.date} ${exception.kind}`
                .toLowerCase()
                .includes(search.value.trim().toLowerCase()),
        )
        .toSorted((a, b) => a.date.localeCompare(b.date))
        .map((exception) => ({ ...exception, id: exception.date })),
);
const days = computed(() =>
    academicWeekdays.map((name, index) => ({
        name,
        weekday: index + 1,
        hours: props.period.calendars.find(
            (calendar) => calendar.weekday === index + 1,
        ),
    })),
);
const selection = ref<{
    weekday?: number;
    exception?: CalendarException;
    key: string;
} | null>(null);
const open = ref(false);
const trigger = ref<HTMLElement | null>(null);
function edit(
    event: MouseEvent,
    weekday?: number,
    exception?: CalendarException,
): void {
    trigger.value =
        event.currentTarget instanceof HTMLElement ? event.currentTarget : null;
    selection.value = {
        weekday,
        exception,
        key: weekday ? `weekday-${weekday}` : (exception?.date ?? 'new'),
    };
    open.value = true;
}
function restoreFocus(): void {
    trigger.value?.focus();
}
function saved(): void {
    search.value = '';
    emit('saved');
}
</script>

<template>
    <div class="min-w-0 space-y-5">
        <section
            class="overflow-hidden rounded-lg border border-border/70 bg-card"
            aria-label="Weekly teaching hours"
        >
            <header
                class="flex flex-wrap items-start justify-between gap-3 border-b border-border/70 px-5 py-4"
            >
                <div>
                    <h2 class="font-semibold">Weekly teaching hours</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ period.name }} · {{ timezone }}
                    </p>
                </div>
                <Badge variant="outline"
                    >{{ period.calendars.length }} of 7 days configured</Badge
                >
            </header>
            <p
                v-if="!canEdit"
                class="border-b bg-muted/30 px-5 py-3 text-sm text-muted-foreground"
            >
                View only. Calendar changes require an open year and academic
                management access.
            </p>
            <ul class="divide-y divide-border/70">
                <li
                    v-for="day in days"
                    :key="day.weekday"
                    class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3"
                    :data-test="`calendar-weekday-${day.weekday}`"
                >
                    <div
                        class="grid min-w-0 flex-1 gap-1 sm:grid-cols-[7rem_minmax(0,1fr)] sm:items-center"
                    >
                        <span class="text-sm font-medium">{{ day.name }}</span>
                        <span
                            class="text-sm"
                            :class="
                                day.hours
                                    ? 'font-mono tabular-nums'
                                    : 'text-muted-foreground'
                            "
                            >{{
                                day.hours
                                    ? `${formatAcademicTime(day.hours.starts_at_minute)} - ${formatAcademicTime(day.hours.ends_at_minute)}`
                                    : 'No teaching hours'
                            }}</span
                        >
                    </div>
                    <Button
                        v-if="canEdit"
                        type="button"
                        variant="ghost"
                        size="sm"
                        :aria-label="`${day.hours ? 'Edit' : 'Set'} ${day.name} hours`"
                        @click="edit($event, day.weekday)"
                        >{{ day.hours ? 'Edit hours' : 'Set hours' }}</Button
                    >
                </li>
            </ul>
        </section>
        <section
            class="overflow-hidden rounded-lg border border-border/70 bg-card"
            aria-label="Date exceptions"
        >
            <header
                class="flex flex-wrap items-center justify-between gap-4 border-b border-border/70 px-5 py-4"
            >
                <div>
                    <h2 class="font-semibold">Date exceptions</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Holidays, blocked dates, and special teaching days
                        override the weekly calendar.
                    </p>
                </div>
                <Button
                    v-if="canEdit"
                    type="button"
                    variant="outline"
                    @click="edit($event)"
                    ><Plus class="size-4" />Add exception</Button
                >
            </header>
            <div
                v-if="period.exceptions.length"
                class="flex items-end gap-3 p-4"
            >
                <div class="grid flex-1 gap-1.5">
                    <Label for="calendar-exception-search"
                        >Find an exception</Label
                    ><Input
                        id="calendar-exception-search"
                        v-model="search"
                        type="search"
                        placeholder="Search name, date, or type"
                    />
                </div>
                <Button
                    v-if="search"
                    type="button"
                    variant="ghost"
                    @click="search = ''"
                    >Clear</Button
                >
            </div>
            <RecordDirectory
                :records="exceptions"
                :columns="[
                    { key: 'date', label: 'Date & name' },
                    { key: 'effect', label: 'Schedule effect' },
                    ...(canEdit ? [{ key: 'actions', label: 'Actions' }] : []),
                ]"
                label="Calendar exceptions"
                :empty="
                    period.exceptions.length
                        ? 'No exceptions match your search. Clear the search to see all dates.'
                        : 'No exceptions yet. Classes follow the weekly teaching hours.'
                "
                row-test="calendar-exception-row"
            >
                <template #date="{ record }"
                    ><p class="font-medium">{{ record.name }}</p>
                    <p class="mt-1 text-xs text-muted-foreground tabular-nums">
                        {{ formatAcademicDate(record.date) }}
                    </p></template
                >
                <template #effect="{ record }"
                    ><Badge variant="secondary">{{
                        kinds.find((kind) => kind.value === record.kind)
                            ?.label ?? record.kind
                    }}</Badge>
                    <p class="mt-1.5 text-xs text-muted-foreground">
                        {{
                            record.kind !== 'teaching'
                                ? 'No classes all day'
                                : record.starts_at_minute !== null &&
                                    record.ends_at_minute !== null
                                  ? `${formatAcademicTime(record.starts_at_minute)} - ${formatAcademicTime(record.ends_at_minute)}`
                                  : 'Normal weekday hours'
                        }}
                    </p></template
                >
                <template #actions="{ record }"
                    ><Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        :aria-label="`Edit ${record.name}`"
                        @click="edit($event, undefined, record)"
                        >Edit exception</Button
                    ></template
                >
            </RecordDirectory>
        </section>
        <CalendarEntryModal
            v-if="selection && canEdit"
            :key="`${period.id}:${selection.key}`"
            v-model:open="open"
            :organization-slug="organizationSlug"
            :period="period"
            :timezone="timezone"
            :weekday="selection.weekday"
            :exception="selection.exception"
            :kinds="kinds"
            @saved="saved"
            @closed="restoreFocus"
        />
    </div>
</template>
