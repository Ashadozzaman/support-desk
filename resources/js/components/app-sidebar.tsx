import { Link } from '@inertiajs/react';
import { BookOpen, Clock, FolderGit2, LayoutGrid, TicketIcon } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCan } from '@/hooks/use-can';
import { dashboard } from '@/routes';
import { index as businessHoursIndex } from '@/routes/admin/business-hours';
import { index as slaPoliciesIndex } from '@/routes/admin/sla-policies';
import { index as ticketsIndex } from '@/routes/tickets';
import type { NavItem } from '@/types';

const baseNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Tickets',
        href: ticketsIndex(),
        icon: TicketIcon,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const canManageSla = useCan('sla.manage');
    const canManageBusinessHours = useCan('business-hours.manage');

    const mainNavItems: NavItem[] = [
        ...baseNavItems,
        ...(canManageSla
            ? [{ title: 'SLA Policies', href: slaPoliciesIndex(), icon: Clock } satisfies NavItem]
            : []),
        ...(canManageBusinessHours
            ? [{ title: 'Business Hours', href: businessHoursIndex(), icon: Clock } satisfies NavItem]
            : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
