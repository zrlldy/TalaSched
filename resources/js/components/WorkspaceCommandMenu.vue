<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { useEventListener } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useWorkspaceNavigation } from '@/composables/useWorkspaceNavigation';

const open = ref(false);
const search = ref('');
const results = ref<HTMLElement | null>(null);
const groups = useWorkspaceNavigation();
const matches = computed(() =>
    groups.value
        .map((group) => ({
            ...group,
            items: group.items.filter((item) =>
                (item.title + ' ' + group.title)
                    .toLowerCase()
                    .includes(search.value.trim().toLowerCase()),
            ),
        }))
        .filter((group) => group.items.length > 0),
);
watch(open, () => {
    search.value = '';
});
useEventListener('keydown', (event: KeyboardEvent) => {
    if (
        (event.metaKey || event.ctrlKey) &&
        event.key.toLowerCase() === 'k' &&
        !event.altKey &&
        !event.shiftKey
    ) {
        event.preventDefault();
        open.value = !open.value;
    }
});
function focusResult(direction: number) {
    const links = Array.from(
        results.value?.querySelectorAll<HTMLAnchorElement>('a') ?? [],
    );

    if (!links.length) {
        return;
    }

    const index = links.findIndex((link) => link === document.activeElement);
    links[(index + direction + links.length) % links.length]?.focus();
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button
                variant="outline"
                class="h-10 gap-2 text-muted-foreground"
                aria-label="Find a page"
                data-test="workspace-search"
            >
                <Search class="size-4" />
                <span class="hidden sm:inline">Find a page...</span>
                <kbd
                    class="ml-5 hidden rounded border bg-muted px-1.5 font-mono text-xs lg:inline"
                    >Ctrl / ⌘ K</kbd
                >
            </Button>
        </DialogTrigger>
        <DialogContent class="gap-0 p-0 sm:max-w-xl">
            <DialogHeader class="px-5 pt-5 pb-4 text-left">
                <DialogTitle>Where would you like to go?</DialogTitle>
                <DialogDescription
                    >Search pages in your current
                    organization.</DialogDescription
                >
            </DialogHeader>
            <div class="border-y px-5 py-3">
                <Input
                    v-model="search"
                    type="search"
                    aria-label="Search pages"
                    placeholder="Try faculty, rooms, or exports"
                    class="h-11"
                    @keydown.down.prevent="focusResult(1)"
                />
            </div>
            <div
                ref="results"
                class="max-h-[min(55vh,28rem)] overflow-y-auto p-3"
                @keydown.down.prevent="focusResult(1)"
                @keydown.up.prevent="focusResult(-1)"
            >
                <div v-for="group in matches" :key="group.title" class="mb-2">
                    <p
                        class="px-3 py-2 text-xs font-medium text-muted-foreground"
                    >
                        {{ group.title }}
                    </p>
                    <Link
                        v-for="item in group.items"
                        :key="item.title"
                        :href="item.href"
                        class="flex min-h-11 items-center gap-3 rounded-md px-3 py-2 text-sm hover:bg-accent focus:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        @click="open = false"
                    >
                        <component
                            :is="item.icon"
                            class="size-4 text-muted-foreground"
                        />
                        {{ item.title }}
                    </Link>
                </div>
                <p
                    v-if="matches.length === 0"
                    class="px-3 py-8 text-center text-sm text-muted-foreground"
                    role="status"
                >
                    No pages match "{{ search }}". Try another name.
                </p>
            </div>
        </DialogContent>
    </Dialog>
</template>
