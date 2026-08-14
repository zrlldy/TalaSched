<script setup lang="ts">
import { CircleAlert } from '@lucide/vue';
import { computed, useId } from 'vue';
import type { ValidationErrors } from '@/types';

type Props = {
    errors: ValidationErrors;
    fieldLabels?: Record<string, string>;
    fieldIds?: Record<string, string>;
    title?: string;
    description?: string;
};

const props = defineProps<Props>();

const entries = computed(() =>
    Object.entries(props.errors)
        .map(([field, value]) => {
            const messages = (Array.isArray(value) ? value : [value]).filter(
                (message): message is string => Boolean(message),
            );

            return {
                field,
                label: props.fieldLabels?.[field] ?? readableFieldName(field),
                fieldId: props.fieldIds?.[field],
                messages: Array.from(new Set(messages)),
            };
        })
        .filter((entry) => entry.messages.length > 0),
);

const messageCount = computed(() =>
    entries.value.reduce((total, entry) => total + entry.messages.length, 0),
);
const defaultTitle = computed(
    () =>
        `${entries.value.length} ${entries.value.length === 1 ? 'field needs' : 'fields need'} attention`,
);
const headingId = useId();
const descriptionId = useId();

function readableFieldName(field: string): string {
    return field
        .split('.')
        .map((segment) => segment.replaceAll('_', ' '))
        .join(' · ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}
</script>

<template>
    <section
        v-if="entries.length > 0"
        data-slot="validation-summary"
        role="alert"
        aria-live="assertive"
        :aria-labelledby="headingId"
        :aria-describedby="descriptionId"
        tabindex="-1"
        class="relative overflow-hidden rounded-lg border border-conflict/35 bg-card text-card-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
    >
        <div
            aria-hidden="true"
            class="absolute inset-y-0 left-0 w-1 bg-conflict"
        />

        <header
            class="flex items-start gap-3 border-b bg-conflict/8 px-4 py-4 sm:px-5"
        >
            <div
                aria-hidden="true"
                class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-md border border-conflict/30 bg-conflict/12 text-foreground"
            >
                <CircleAlert class="size-4" />
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h2
                        :id="headingId"
                        class="text-sm font-semibold text-foreground"
                    >
                        {{ title ?? defaultTitle }}
                    </h2>
                    <span
                        class="rounded-sm bg-conflict px-1.5 py-0.5 font-schedule text-[0.625rem] font-semibold tracking-[0.12em] text-conflict-foreground uppercase"
                    >
                        {{ messageCount }}
                        {{ messageCount === 1 ? 'correction' : 'corrections' }}
                    </span>
                </div>
                <p
                    :id="descriptionId"
                    class="mt-1 text-sm leading-5 text-muted-foreground"
                >
                    {{
                        description ??
                        'Review the highlighted fields, then save your changes again.'
                    }}
                </p>
            </div>
        </header>

        <ul class="divide-y divide-border" role="list">
            <li
                v-for="entry in entries"
                :key="entry.field"
                class="grid gap-1 px-4 py-3 sm:grid-cols-[10rem_minmax(0,1fr)] sm:gap-4 sm:px-5"
            >
                <div class="min-w-0">
                    <a
                        v-if="entry.fieldId"
                        :href="`#${entry.fieldId}`"
                        class="inline-flex rounded-sm font-schedule text-xs font-semibold text-foreground underline decoration-conflict/60 underline-offset-4 outline-none hover:decoration-conflict focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        {{ entry.label }}
                    </a>
                    <span
                        v-else
                        class="font-schedule text-xs font-semibold text-foreground"
                    >
                        {{ entry.label }}
                    </span>
                </div>

                <ul class="grid gap-1 text-sm leading-5 text-muted-foreground">
                    <li v-for="message in entry.messages" :key="message">
                        {{ message }}
                    </li>
                </ul>
            </li>
        </ul>
    </section>
</template>
