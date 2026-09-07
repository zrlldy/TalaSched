<script setup lang="ts">
defineProps<{
    sections: { value: string; label: string; count?: number }[];
    selected: string;
    label: string;
}>();
defineEmits<{ select: [value: string] }>();
</script>

<template>
    <nav
        :aria-label="label"
        class="flex flex-wrap gap-1 border-b border-border pb-3"
    >
        <button
            v-for="section in sections"
            :key="section.value"
            type="button"
            :aria-pressed="selected === section.value"
            class="inline-flex min-h-10 items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :class="
                selected === section.value
                    ? 'bg-primary text-primary-foreground'
                    : 'text-muted-foreground hover:bg-muted hover:text-foreground'
            "
            @click="$emit('select', section.value)"
        >
            {{ section.label }}
            <span
                v-if="section.count !== undefined"
                class="rounded bg-current/10 px-1.5 font-mono text-xs"
                >{{ section.count }}</span
            >
        </button>
    </nav>
</template>
