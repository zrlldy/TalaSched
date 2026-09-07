import { usePage } from '@inertiajs/vue3';
import {
    CalendarDays,
    CalendarRange,
    ClipboardCheck,
    Clock3,
    DoorOpen,
    FileSpreadsheet,
    GraduationCap,
    LayoutGrid,
    LibraryBig,
    ReceiptText,
    ShieldCheck,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import { toUrl } from '@/lib/utils';
import { dashboard } from '@/routes';
import { setup as academicSetup } from '@/routes/academic';
import { inbox } from '@/routes/approvals';
import { index as auditIndex } from '@/routes/audits';
import {
    index as organizations,
    edit as organizationShow,
} from '@/routes/organizations';
import { setup as resourceSetup } from '@/routes/resources';
import { index as timetables } from '@/routes/scheduling/timetables';
import { show as subscriptionShow } from '@/routes/subscriptions';
import { index as templates } from '@/routes/templates';
import type { NavGroup, NavItem } from '@/types';

export function useWorkspaceNavigation() {
    const page = usePage();

    return computed<NavGroup[]>(() => {
        const organization = page.props.currentOrganization;

        if (!organization) {
            return [
                {
                    title: 'Workspace',
                    items: [
                        {
                            title: 'Organizations',
                            href: organizations(),
                            icon: Users,
                        },
                    ],
                },
            ];
        }

        const slug = organization.slug;
        const current = new URL(page.url, 'http://localhost');
        const item = (
            title: string,
            href: NavItem['href'],
            icon: NavItem['icon'],
        ): NavItem => {
            const target = new URL(toUrl(href), 'http://localhost');
            const section =
                current.searchParams.get('section') ??
                (current.pathname === academicSetup(slug).url
                    ? 'years'
                    : 'faculty');

            return {
                title,
                href,
                icon,
                isActive: target.searchParams.has('section')
                    ? current.pathname === target.pathname &&
                      section === target.searchParams.get('section')
                    : current.pathname === target.pathname ||
                      current.pathname.startsWith(target.pathname + '/'),
            };
        };
        const groups: NavGroup[] = [
            {
                title: 'Workspace',
                items: [
                    item('Dashboard', dashboard(slug), LayoutGrid),
                    item('Timetables', timetables(slug), CalendarDays),
                    item('Approval inbox', inbox(slug), ClipboardCheck),
                ],
            },
            {
                title: 'Prepare your schedule',
                items: [
                    item('Academic setup', academicSetup(slug), CalendarRange),
                    item(
                        'Faculty',
                        resourceSetup(slug, { query: { section: 'faculty' } }),
                        Users,
                    ),
                    item(
                        'Rooms',
                        resourceSetup(slug, { query: { section: 'rooms' } }),
                        DoorOpen,
                    ),
                    item(
                        'Subjects',
                        resourceSetup(slug, { query: { section: 'subjects' } }),
                        LibraryBig,
                    ),
                    item(
                        'Offerings',
                        resourceSetup(slug, {
                            query: { section: 'offerings' },
                        }),
                        GraduationCap,
                    ),
                    item(
                        'Availability',
                        resourceSetup(slug, {
                            query: { section: 'availability' },
                        }),
                        Clock3,
                    ),
                ],
            },
        ];
        const administration: NavItem[] = [];

        if (page.props.canManageTemplates) {
            groups[0].items.push(
                item('Templates & exports', templates(slug), FileSpreadsheet),
            );
        }

        administration.push(
            item('Members & settings', organizationShow(slug), Users),
        );

        if (page.props.canManageSubscription) {
            administration.push(
                item('Plan & usage', subscriptionShow(slug), ReceiptText),
            );
        }

        if (page.props.canViewAudit) {
            administration.push(
                item('Activity ledger', auditIndex(slug), ShieldCheck),
            );
        }

        groups.push({ title: 'Organization', items: administration });

        return groups;
    });
}
