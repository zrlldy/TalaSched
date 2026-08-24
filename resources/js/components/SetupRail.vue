<script setup lang="ts">
import { Check } from '@lucide/vue';
import { computed } from 'vue';

export type SetupRailStep = {
    label: string;
    description: string;
    href: string;
    complete: boolean;
};

const props = withDefaults(
    defineProps<{
        steps: SetupRailStep[];
        eyebrow?: string;
        title?: string;
    }>(),
    {
        eyebrow: 'Suggested order',
        title: 'Start here, then move down the page.',
    },
);

const firstIncompleteIndex = computed(() => {
    const index = props.steps.findIndex((step) => !step.complete);

    return index === -1 ? props.steps.length : index;
});
</script>

<template>
    <nav
        aria-label="Setup progress"
        class="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm"
    >
        <div
            class="flex flex-col gap-4 px-4 py-4 sm:px-5 lg:flex-row lg:items-center lg:gap-8"
        >
            <div class="shrink-0 lg:w-52">
                <p
                    class="font-mono text-[10px] font-semibold tracking-[0.2em] text-primary uppercase"
                >
                    {{ eyebrow }}
                </p>
                <p class="mt-1 text-sm font-semibold tracking-tight">
                    {{ title }}
                </p>
            </div>

            <ol
                class="flex min-w-0 flex-1 gap-2 overflow-x-auto pb-1 lg:gap-0 lg:overflow-visible lg:pb-0"
            >
                <li
                    v-for="(step, index) in steps"
                    :key="step.label"
                    class="flex min-w-[11rem] flex-1 items-center gap-2 lg:min-w-0"
                >
                    <a
                        :href="step.href"
                        class="group flex min-w-0 items-center gap-2 rounded-lg p-1.5 transition-colors outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                        :aria-current="
                            index === firstIncompleteIndex ? 'step' : undefined
                        "
                    >
                        <span
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border font-mono text-xs font-semibold transition-colors"
                            :class="
                                step.complete
                                    ? 'border-available/30 bg-available/10 text-available'
                                    : index === firstIncompleteIndex
                                      ? 'border-primary bg-primary text-primary-foreground shadow-sm'
                                      : 'border-border bg-muted text-muted-foreground'
                            "
                        >
                            <Check v-if="step.complete" class="h-4 w-4" />
                            <span v-else>{{ index + 1 }}</span>
                        </span>
                        <span class="min-w-0">
                            <span
                                class="block truncate text-xs font-semibold"
                                >{{ step.label }}</span
                            >
                            <span
                                class="block truncate text-[11px] text-muted-foreground"
                            >
                                {{
                                    step.complete
                                        ? 'Ready'
                                        : index === firstIncompleteIndex
                                          ? 'Start here'
                                          : step.description
                                }}
                            </span>
                        </span>
                    </a>

                    <span
                        v-if="index < steps.length - 1"
                        aria-hidden="true"
                        class="hidden h-px min-w-4 flex-1 bg-border lg:block"
                    />
                </li>
            </ol>
        </div>
    </nav>
</template>
