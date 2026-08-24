<script setup lang="ts">
defineProps<{
    message?: string;
}>();
</script>

<template>
    <div v-show="message">
        <p class="mt-1 text-sm leading-5 font-medium text-conflict">
            {{ message }}
        </p>
    </div>
</template>
