import { AgentWorkloadCard } from "@/components/cards/AgentWorkloadCard";
import { StatCard } from "@/components/cards/StatCard";
import { PriorityBarChart } from "@/components/charts/PriorityBarChart";
import { TicketsTrendChart } from "@/components/charts/TicketsTrendChart";
import { ApproachingSlaTable } from "@/components/ticket/ApproachingSlaTable";
import { useStaffQueue } from "@/hooks/use-staff-queue";
import { dashboard } from "@/routes";
import type {
    AgentBreakdown,
    DashboardScope,
    DashboardStats,
    PriorityBreakdown,
    TicketsTrendPoint,
} from "@/types/dashboard";
import type { TicketListItem } from "@/types/ticket";
import { Head } from "@inertiajs/react";
import {
    AlertTriangleIcon,
    CheckCircle2Icon,
    ClockIcon,
    TicketIcon,
    TimerIcon,
    UsersIcon,
} from "lucide-react";

export default function Dashboard({
    scope,
    stats,
    byPriority,
    byAgent,
    trend,
    approachingSla,
}: {
    scope: DashboardScope;
    stats: DashboardStats;
    byPriority: PriorityBreakdown[];
    byAgent: AgentBreakdown[];
    trend: TicketsTrendPoint[];
    approachingSla: TicketListItem[];
}) {
    useStaffQueue([
        "stats",
        "byPriority",
        "byAgent",
        "trend",
        "approachingSla",
    ]);

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="grid auto-rows-min gap-4 md:grid-cols-3">
                    <StatCard
                        label="Open tickets"
                        value={stats.open_total}
                        icon={TicketIcon}
                    />
                    {stats.unassigned !== null && (
                        <StatCard
                            label="Unassigned"
                            value={stats.unassigned}
                            icon={UsersIcon}
                        />
                    )}
                    {stats.critical !== null && (
                        <StatCard
                            label="Critical"
                            value={stats.critical}
                            icon={AlertTriangleIcon}
                        />
                    )}
                    <StatCard
                        label="SLA breached"
                        value={stats.sla_breached}
                        icon={ClockIcon}
                    />
                    <StatCard
                        label="SLA approaching"
                        value={stats.sla_approaching}
                        icon={TimerIcon}
                    />
                    <StatCard
                        label="Resolved"
                        value={stats.resolved}
                        icon={CheckCircle2Icon}
                    />
                </div>

                {scope === "global" && (
                    <div className="grid gap-4 md:grid-cols-2">
                        <PriorityBarChart data={byPriority} />
                        <TicketsTrendChart data={trend} />
                    </div>
                )}

                <AgentWorkloadCard rows={byAgent} />
                <div className="flex flex-col gap-2">
                    <h2 className="text-lg font-semibold">Approaching SLA</h2>
                    <ApproachingSlaTable tickets={approachingSla} />
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: "Dashboard",
            href: dashboard(),
        },
    ],
};
