<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    CalendarClock,
    CalendarRange,
    ClipboardCheck,
    LayoutGrid,
    PanelsTopLeft,
    ReceiptText,
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
import { show as subscriptionShow } from '@/routes/subscriptions';
import type { NavGroup, NavItem } from '@/types';

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

const mainNavGroups = computed<NavGroup[]>(() => {
    const groups: NavGroup[] = [
        {
            title: 'Workspace',
            items: [
                {
                    title: 'Dashboard',
                    href: dashboardUrl.value,
                    icon: LayoutGrid,
                },
            ],
        },
    ];

    if (page.props.currentOrganization) {
        const setupItems: NavItem[] = [
            {
                title: 'Academic setup',
                href: academicSetup(page.props.currentOrganization.slug).url,
                icon: CalendarRange,
            },
        ];

        groups.push({
            title: 'Setup',
            items: setupItems,
        });

        groups.push({
            title: 'Resources',
            items: [
                {
                    title: 'Resources & catalog',
                    href: resourceSetup(page.props.currentOrganization.slug)
                        .url,
                    icon: PanelsTopLeft,
                },
            ],
        });

        if (timetableUrl.value !== null) {
            groups.push({
                title: 'Scheduling',
                items: [
                    {
                        title: 'Timetable',
                        href: timetableUrl.value,
                        icon: CalendarClock,
                    },
                ],
            });
        }

        groups.push({
            title: 'Approvals',
            items: [
                {
                    title: 'Approval inbox',
                    href: approvalInbox(page.props.currentOrganization.slug)
                        .url,
                    icon: ClipboardCheck,
                },
            ],
        });

        if (page.props.canManageSubscription) {
            groups.push({
                title: 'Billing',
                items: [
                    {
                        title: 'Plan & usage',
                        href: subscriptionShow(
                            page.props.currentOrganization.slug,
                        ).url,
                        icon: ReceiptText,
                    },
                ],
            });
        }
    }

    return groups;
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
            <NavMain :groups="mainNavGroups" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
