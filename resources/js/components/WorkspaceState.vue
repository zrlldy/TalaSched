<script setup lang="ts">
import {
    CalendarClock,
    CircleAlert,
    LoaderCircle,
    LockKeyhole,
    ShieldX,
} from '@lucide/vue';
import { computed, useId } from 'vue';

type WorkspaceStateVariant =
    'empty' | 'loading' | 'error' | 'authorization' | 'entitlement';

type Props = {
    variant: WorkspaceStateVariant;
    title: string;
    description: string;
};

const props = defineProps<Props>();

const statePresentations = {
    empty: {
        label: 'Ready for setup',
        icon: CalendarClock,
        accentClass: 'bg-schedule',
        iconClass: 'border-schedule/25 bg-schedule/10 text-schedule',
        labelClass: 'text-schedule',
    },
    loading: {
        label: 'Loading workspace',
        icon: LoaderCircle,
        accentClass: 'bg-schedule',
        iconClass: 'border-schedule/25 bg-schedule/10 text-schedule',
        labelClass: 'text-schedule',
    },
    error: {
        label: 'Action needed',
        icon: CircleAlert,
        accentClass: 'bg-conflict',
        iconClass: 'border-conflict/25 bg-conflict/10 text-foreground',
        labelClass: 'text-foreground',
    },
    authorization: {
        label: 'Access restricted',
        icon: ShieldX,
        accentClass: 'bg-warning',
        iconClass: 'border-warning/30 bg-warning/15 text-foreground',
        labelClass: 'text-foreground',
    },
    entitlement: {
        label: 'Plan access',
        icon: LockKeyhole,
        accentClass: 'bg-warning',
        iconClass: 'border-warning/30 bg-warning/15 text-foreground',
        labelClass: 'text-foreground',
    },
} as const;

const presentation = computed(() => statePresentations[props.variant]);
const headingId = useId();
const descriptionId = useId();
const semanticRole = computed(() => {
    if (props.variant === 'error') {
        return 'alert';
    }

    if (props.variant === 'loading') {
        return 'status';
    }

    return 'region';
});
</script>

<template>
    <section
        data-slot="workspace-state"
        :data-variant="variant"
        :role="semanticRole"
        :aria-labelledby="headingId"
        :aria-describedby="descriptionId"
        :aria-live="
            variant === 'error'
                ? 'assertive'
                : variant === 'loading'
                  ? 'polite'
                  : undefined
        "
        :aria-busy="variant === 'loading' ? 'true' : undefined"
        class="relative overflow-hidden rounded-lg border bg-card px-5 py-6 text-card-foreground sm:px-6 sm:py-7"
    >
        <div
            aria-hidden="true"
            :class="presentation.accentClass"
            class="absolute inset-y-0 left-0 w-1"
        />

        <div class="flex items-start gap-4 sm:gap-5">
            <div
                aria-hidden="true"
                :class="presentation.iconClass"
                class="relative flex size-10 shrink-0 items-center justify-center rounded-md border"
            >
                <component
                    :is="presentation.icon"
                    class="size-5"
                    :class="{
                        'motion-safe:animate-spin': variant === 'loading',
                    }"
                />
            </div>

            <div class="min-w-0 flex-1">
                <p
                    :class="presentation.labelClass"
                    class="font-schedule text-[0.6875rem] font-semibold tracking-[0.16em] uppercase"
                >
                    {{ presentation.label }}
                </p>
                <h2
                    :id="headingId"
                    class="mt-2 text-base font-semibold tracking-tight text-foreground sm:text-lg"
                >
                    {{ title }}
                </h2>
                <p
                    :id="descriptionId"
                    class="mt-1 max-w-2xl text-sm leading-6 text-muted-foreground"
                >
                    {{ description }}
                </p>

                <div
                    v-if="$slots.details"
                    class="mt-3 font-schedule text-xs leading-5 text-muted-foreground"
                >
                    <slot name="details" />
                </div>

                <div
                    v-if="$slots.action"
                    class="mt-5 flex flex-wrap items-center gap-3"
                >
                    <slot name="action" />
                </div>
            </div>
        </div>
    </section>
</template>
