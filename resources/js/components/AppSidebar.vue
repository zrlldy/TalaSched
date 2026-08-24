<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { CalendarRange, LayoutGrid, PanelsTopLeft } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import OrganizationSwitcher from '@/components/OrganizationSwitcher.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { setup as academicSetup } from '@/routes/academic';
import { index as organizations } from '@/routes/organizations';
import { setup as resourceSetup } from '@/routes/resources';
import type { NavItem } from '@/types';

const page = usePage();

const dashboardUrl = computed(() =>
    page.props.currentOrganization
        ? dashboard(page.props.currentOrganization.slug).url
        : organizations().url,
);

const mainNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboardUrl.value,
            icon: LayoutGrid,
        },
    ];

    if (page.props.currentOrganization) {
        items.push({
            title: 'Academic setup',
            href: academicSetup(page.props.currentOrganization.slug).url,
            icon: CalendarRange,
        });
        items.push({
            title: 'Resources & catalog',
            href: resourceSetup(page.props.currentOrganization.slug).url,
            icon: PanelsTopLeft,
        });
    }

    return items;
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboardUrl">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <SidebarMenu>
                <SidebarMenuItem>
                    <OrganizationSwitcher />
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent aria-label="Workspace navigation">
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
