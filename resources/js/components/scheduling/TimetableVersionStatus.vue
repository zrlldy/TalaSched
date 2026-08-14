<script setup lang="ts">
import {
    Archive,
    BadgeCheck,
    Clock3,
    MessageSquareWarning,
    PencilLine,
    RadioTower,
} from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import type { TimetableVersionStatus } from '@/types';

type Props = {
    status: TimetableVersionStatus;
};

type StatusPresentation = {
    label: string;
    icon: Component;
    className: string;
};

const props = defineProps<Props>();

const statusPresentations: Record<TimetableVersionStatus, StatusPresentation> =
    {
        draft: {
            label: 'Draft',
            icon: PencilLine,
            className: 'border-schedule/30 bg-schedule/10 text-foreground',
        },
        in_review: {
            label: 'In review',
            icon: Clock3,
            className: 'border-warning/40 bg-warning/12 text-foreground',
        },
        changes_requested: {
            label: 'Changes requested',
            icon: MessageSquareWarning,
            className: 'border-conflict/35 bg-conflict/10 text-foreground',
        },
        approved: {
            label: 'Approved',
            icon: BadgeCheck,
            className: 'border-available/35 bg-available/12 text-foreground',
        },
        published: {
            label: 'Published',
            icon: RadioTower,
            className:
                'border-available bg-available text-available-foreground shadow-xs',
        },
        superseded: {
            label: 'Superseded',
            icon: Archive,
            className: 'border-border bg-muted text-muted-foreground',
        },
    };

const presentation = computed(() => statusPresentations[props.status]);
</script>

<template>
    <span
        data-slot="timetable-version-status"
        :data-status="status"
        class="inline-flex w-fit items-center gap-1.5 rounded-sm border px-2 py-1 font-schedule text-[0.6875rem] leading-none font-semibold tracking-[0.08em] uppercase"
        :class="presentation.className"
    >
        <component
            :is="presentation.icon"
            aria-hidden="true"
            class="size-3.5 shrink-0"
        />
        <span>{{ presentation.label }}</span>
    </span>
</template>
