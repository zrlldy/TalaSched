<script setup lang="ts">
import { computed, useId } from 'vue';

defineOptions({ inheritAttrs: false });
defineProps<{
    name: string;
    id?: string;
    label?: string;
    allowEndOfDay?: boolean;
    required?: boolean;
    disabled?: boolean;
}>();
const generatedId = useId();
const minutes = defineModel<number | ''>({ default: '' });
const time = computed({
    get: () =>
        minutes.value === '' || minutes.value === 1440
            ? ''
            : `${Math.floor(minutes.value / 60)
                  .toString()
                  .padStart(
                      2,
                      '0',
                  )}:${(minutes.value % 60).toString().padStart(2, '0')}`,
    set: (value: string) => {
        const [hours, remainder] = value.split(':').map(Number);
        minutes.value = value ? hours * 60 + remainder : '';
    },
});
const endOfDay = computed({
    get: () => minutes.value === 1440,
    set: (value: boolean) => {
        minutes.value = value ? 1440 : '';
    },
});
</script>

<template>
    <div class="min-w-0 space-y-1">
        <label
            v-if="label"
            :for="id ?? generatedId"
            class="text-xs font-medium"
            >{{ label }}</label
        >
        <input
            :id="id ?? generatedId"
            v-model="time"
            v-bind="$attrs"
            type="time"
            :required="required && !endOfDay"
            :disabled="disabled || endOfDay"
            class="flex h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50"
        />
        <input type="hidden" :name="name" :value="minutes" />
        <label
            v-if="allowEndOfDay"
            class="flex min-h-8 items-center gap-2 text-xs text-muted-foreground"
        >
            <input
                v-model="endOfDay"
                type="checkbox"
                :disabled="disabled"
                class="size-4 accent-primary"
            />
            Midnight (end of day)
        </label>
    </div>
</template>
