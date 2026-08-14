<script setup lang="ts">
type Props = {
    title: string;
    description?: string;
    section?: string;
};

defineProps<Props>();
</script>

<template>
    <header data-slot="workspace-page-header" class="@container relative pb-5">
        <div
            class="flex flex-col gap-5 @3xl:flex-row @3xl:items-end @3xl:justify-between"
        >
            <div class="flex min-w-0 items-start gap-3 sm:gap-4">
                <div
                    aria-hidden="true"
                    class="mt-1 grid w-2 shrink-0 grid-rows-[0.55rem_1fr] gap-1 self-stretch"
                >
                    <span class="rounded-xs bg-schedule" />
                    <span class="rounded-xs bg-schedule/20" />
                </div>

                <div class="min-w-0">
                    <p
                        v-if="section"
                        class="font-schedule text-[0.6875rem] leading-4 font-semibold tracking-[0.16em] text-muted-foreground uppercase"
                    >
                        {{ section }}
                    </p>

                    <div
                        class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-2"
                        :class="section ? 'mt-1.5' : ''"
                    >
                        <h1
                            class="min-w-0 text-2xl leading-tight font-semibold tracking-[-0.025em] text-balance text-foreground sm:text-[1.75rem]"
                        >
                            {{ title }}
                        </h1>

                        <div
                            v-if="$slots.status"
                            data-slot="workspace-page-header-status"
                            class="flex shrink-0 items-center"
                        >
                            <slot name="status" />
                        </div>
                    </div>

                    <p
                        v-if="description"
                        class="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground sm:text-[0.9375rem]"
                    >
                        {{ description }}
                    </p>

                    <div
                        v-if="$slots.metadata"
                        data-slot="workspace-page-header-metadata"
                        class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 border-l border-schedule/35 pl-3 font-schedule text-xs leading-5 text-muted-foreground"
                    >
                        <slot name="metadata" />
                    </div>
                </div>
            </div>

            <div
                v-if="$slots.actions"
                data-slot="workspace-page-header-actions"
                class="flex w-full flex-wrap items-center gap-2 pl-5 @3xl:w-auto @3xl:shrink-0 @3xl:justify-end @3xl:pl-0"
            >
                <slot name="actions" />
            </div>
        </div>

        <div
            aria-hidden="true"
            class="absolute inset-x-0 bottom-0 flex items-end"
        >
            <span class="h-0.5 w-24 bg-schedule" />
            <span class="h-px flex-1 bg-border" />
            <span class="h-1 w-px bg-border" />
            <span class="h-px w-5 bg-border" />
            <span class="h-1.5 w-px bg-border" />
        </div>
    </header>
</template>
