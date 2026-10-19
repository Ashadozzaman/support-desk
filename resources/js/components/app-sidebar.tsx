import AppLogo from "@/components/app-logo";
import { NavFooter } from "@/components/nav-footer";
import { NavMain } from "@/components/nav-main";
import { NavUser } from "@/components/nav-user";
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from "@/components/ui/sidebar";
import { useCan } from "@/hooks/use-can";
import { dashboard } from "@/routes";
import { auditLog, systemHealth } from "@/routes/admin";
import { index as agentsIndex } from "@/routes/admin/agents";
import { index as businessHoursIndex } from "@/routes/admin/business-hours";
import { index as importsIndex } from "@/routes/admin/imports";
import { index as kbArticlesIndex } from "@/routes/admin/kb-articles";
import { edit as mailboxEdit } from "@/routes/admin/mailbox";
import { index as rolesIndex } from "@/routes/admin/roles";
import { index as slaPoliciesIndex } from "@/routes/admin/sla-policies";
import { index as usersIndex } from "@/routes/admin/users";
import { show as assistantShow } from "@/routes/assistant";
import { index as helpIndex } from "@/routes/help";
import { show as chatShow } from "@/routes/help/chat";
import { index as reportsIndex } from "@/routes/reports";
import { index as ticketsIndex } from "@/routes/tickets";
import type { NavItem } from "@/types";
import { Link, usePage } from "@inertiajs/react";
import {
    BarChart3Icon,
    BotIcon,
    DatabaseZapIcon,
    BookOpenIcon,
    Clock,
    HeartPulseIcon,
    LifeBuoyIcon,
    LayoutGrid,
    MailIcon,
    ScrollTextIcon,
    ShieldCheckIcon,
    TicketIcon,
    UploadIcon,
    UserCogIcon,
    UsersIcon,
} from "lucide-react";

const baseNavItems: NavItem[] = [
    {
        title: "Dashboard",
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: "Tickets",
        href: ticketsIndex(),
        icon: TicketIcon,
    },
    {
        title: "Help centre",
        href: helpIndex(),
        icon: LifeBuoyIcon,
    },
];

const footerNavItems: NavItem[] = [];

export function AppSidebar() {
    const canManageSla = useCan("sla.manage");
    const canManageBusinessHours = useCan("business-hours.manage");
    const canManageImports = useCan("imports.manage");
    const canManageMailbox = useCan("mailbox.manage");
    const canManageUsers = useCan("users.manage");
    const canManageRoles = useCan("roles.manage");
    const canManageKnowledgeBase = useCan("knowledge-base.manage");
    const { aiChatAvailable, aiDataAssistantAvailable } = usePage().props;
    const canViewReports = useCan("reports.view") || useCan("reports.view-own");
    const canViewMonitoring = useCan("monitoring.view");
    const canViewAuditLog = useCan("audit.view");

    const mainNavItems: NavItem[] = [
        ...baseNavItems,
        ...(aiDataAssistantAvailable
            ? [
                  {
                      title: "Data assistant",
                      href: assistantShow(),
                      icon: DatabaseZapIcon,
                  } satisfies NavItem,
              ]
            : []),
        ...(aiChatAvailable
            ? [
                  {
                      title: "AI help chat",
                      href: chatShow(),
                      icon: BotIcon,
                  } satisfies NavItem,
              ]
            : []),
        ...(canViewReports
            ? [
                  {
                      title: "Reports",
                      href: reportsIndex(),
                      icon: BarChart3Icon,
                  } satisfies NavItem,
              ]
            : []),
        ...(canManageUsers
            ? [
                  {
                      title: "Users",
                      href: usersIndex(),
                      icon: UserCogIcon,
                  } satisfies NavItem,
                  {
                      title: "Agents",
                      href: agentsIndex(),
                      icon: UsersIcon,
                  } satisfies NavItem,
              ]
            : []),
        ...(canManageRoles
            ? [
                  {
                      title: "Roles",
                      href: rolesIndex(),
                      icon: ShieldCheckIcon,
                  } satisfies NavItem,
              ]
            : []),
        ...(canManageKnowledgeBase
            ? [
                  {
                      title: "Knowledge base",
                      href: kbArticlesIndex(),
                      icon: BookOpenIcon,
                  } satisfies NavItem,
              ]
            : []),
        ...(canManageSla
            ? [
                  {
                      title: "SLA Policies",
                      href: slaPoliciesIndex(),
                      icon: Clock,
                  } satisfies NavItem,
              ]
            : []),
        ...(canManageBusinessHours
            ? [
                  {
                      title: "Business Hours",
                      href: businessHoursIndex(),
                      icon: Clock,
                  } satisfies NavItem,
              ]
            : []),
        ...(canManageImports
            ? [
                  {
                      title: "CSV Imports",
                      href: importsIndex(),
                      icon: UploadIcon,
                  } satisfies NavItem,
              ]
            : []),
        ...(canManageMailbox
            ? [
                  {
                      title: "Mailbox",
                      href: mailboxEdit(),
                      icon: MailIcon,
                  } satisfies NavItem,
              ]
            : []),
        ...(canViewMonitoring
            ? [
                  {
                      title: "System health",
                      href: systemHealth(),
                      icon: HeartPulseIcon,
                  } satisfies NavItem,
              ]
            : []),
        ...(canViewAuditLog
            ? [
                  {
                      title: "Audit log",
                      href: auditLog(),
                      icon: ScrollTextIcon,
                  } satisfies NavItem,
              ]
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
