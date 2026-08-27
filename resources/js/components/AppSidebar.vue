<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    CalendarClock,
    CalendarRange,
    ClipboardCheck,
    LayoutGrid,
    PanelsTopLeft,
} from '@lucide/vue';
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
import { inbox as approvalInbox } from '@/routes/approvals';
import { index as organizations } from '@/routes/organizations';
import { setup as resourceSetup } from '@/routes/resources';
import { show as timetableShow } from '@/routes/scheduling/timetables';
import type { NavItem } from '@/types';

const page = usePage();

const dashboardUrl = computed(() =>
    page.props.currentOrganization
        ? dashboard(page.props.currentOrganization.slug).url
        : organizations().url,
);
const timetableUrl = computed(() => {
    const timetableId = page.props.timetableId;

    if (!page.props.currentOrganization || typeof timetableId !== 'string') {
        return null;
    }

    return timetableShow([page.props.currentOrganization.slug, timetableId])
        .url;
});

const mainNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboardUrl.value,
            icon: LayoutGrid,
        },
    ];

    if (page.props.currentOrganization) {
        if (timetableUrl.value !== null) {
            items.push({
                title: 'Timetable',
                href: timetableUrl.value,
                icon: CalendarClock,
            });
        }

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
        items.push({
            title: 'Approvals',
            href: approvalInbox(page.props.currentOrganization.slug).url,
            icon: ClipboardCheck,
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
