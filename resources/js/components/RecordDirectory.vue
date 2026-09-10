<script setup lang="ts" generic="T extends { id: string }">
import { LayoutGrid, List } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    records: T[];
    columns: { key: string; label: string }[];
    label: string;
    empty: string;
    rowTest?: string;
}>();
const view = ref('table');
const rowTest = computed(() => props.rowTest ?? 'record-row');
</script>

<template>
    <div>
        <div
            class="flex items-center justify-between gap-3 border-b border-border/70 px-5 py-2"
        >
            <p class="text-xs text-muted-foreground" aria-live="polite">
                {{ records.length }}
                {{ records.length === 1 ? 'record' : 'records' }} shown
            </p>
            <div
                class="hidden gap-1 md:flex"
                role="group"
                :aria-label="`${label} layout`"
            >
                <Button
                    type="button"
                    size="sm"
                    :variant="view === 'table' ? 'secondary' : 'ghost'"
                    :aria-pressed="view === 'table'"
                    @click="view = 'table'"
                    ><List class="size-3.5" />Table</Button
                >
                <Button
                    type="button"
                    size="sm"
                    :variant="view === 'cards' ? 'secondary' : 'ghost'"
                    :aria-pressed="view === 'cards'"
                    @click="view = 'cards'"
                    ><LayoutGrid class="size-3.5" />Cards</Button
                >
            </div>
        </div>
        <p
            v-if="records.length === 0"
            class="px-5 py-8 text-sm text-muted-foreground"
        >
            {{ empty }}
        </p>
        <template v-else>
            <div
                :class="
                    view === 'table'
                        ? 'hidden overflow-x-auto md:block'
                        : 'hidden'
                "
            >
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">
                        {{
                            label
                        }}
                    </caption>
                    <thead
                        class="border-b bg-muted/30 text-xs text-muted-foreground"
                    >
                        <tr>
                            <th
                                v-for="column in columns"
                                :key="column.key"
                                scope="col"
                                class="px-5 py-3 font-medium"
                            >
                                {{ column.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/70">
                        <tr
                            v-for="record in records"
                            :key="record.id"
                            :data-test="rowTest"
                            class="hover:bg-muted/20"
                        >
                            <td
                                v-for="column in columns"
                                :key="column.key"
                                class="px-5 py-4 align-top"
                            >
                                <slot :name="column.key" :record="record" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div
                class="grid gap-3 p-3 sm:grid-cols-2"
                :class="view === 'table' ? 'md:hidden' : 'xl:grid-cols-3'"
            >
                <article
                    v-for="record in records"
                    :key="record.id"
                    :data-test="rowTest"
                    class="min-w-0 rounded-lg border border-border/70 bg-background p-4"
                >
                    <dl class="grid gap-3">
                        <div
                            v-for="column in columns"
                            :key="column.key"
                            class="min-w-0"
                        >
                            <dt class="mb-1 text-xs text-muted-foreground">
                                {{ column.label }}
                            </dt>
                            <dd class="text-sm break-words">
                                <slot :name="column.key" :record="record" />
                            </dd>
                        </div>
                    </dl>
                </article>
            </div>
        </template>
    </div>
</template>
