<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useSidebar } from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavGroup } from '@/types';

defineProps<{
    groups: NavGroup[];
}>();

const { isCurrentUrl } = useCurrentUrl();
const { setOpenMobile } = useSidebar();
</script>

<template>
    <SidebarGroup v-for="group in groups" :key="group.title" class="px-2 py-0">
        <SidebarGroupLabel>{{ group.title }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in group.items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="item.isActive ?? isCurrentUrl(item.href)"
                    :tooltip="item.title"
                    class="min-h-10"
                >
                    <Link
                        :href="item.href"
                        :aria-current="
                            (item.isActive ?? isCurrentUrl(item.href))
                                ? 'page'
                                : undefined
                        "
                        @click="setOpenMobile(false)"
                    >
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
