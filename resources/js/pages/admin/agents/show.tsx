import { Head, router, usePage } from '@inertiajs/react';
import { DownloadIcon } from 'lucide-react';
import { AgentDailyTable } from '@/components/agent/AgentDailyTable';
import { AgentStatusBreakdownCard } from '@/components/agent/AgentStatusBreakdownCard';
import { FilterCard } from '@/components/cards/FilterCard';
import { TicketsTrendChart } from '@/components/charts/TicketsTrendChart';
import { MonthInput } from '@/components/form/MonthInput';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { dailyExport, index, show } from '@/routes/admin/agents';
import type { AgentDailyStats, AgentStatusBreakdown } from '@/types/agent';
import type { TicketsTrendPoint } from '@/types/dashboard';

type AgentSummary = {
    id: number;
    name: string;
    email: string;
};

export default function AgentsShow({
    agent,
    statusBreakdown,
    completedTrend,
    daily,
}: {
    agent: AgentSummary;
    statusBreakdown: AgentStatusBreakdown;
    completedTrend: TicketsTrendPoint[];
    daily: AgentDailyStats;
}) {
    const { errors } = usePage().props;

    function selectMonth(month: string | null) {
        router.get(
            show(agent.id, { query: { month } }).url,
            {},
            { only: ['daily'], preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <>
            <Head title={agent.name} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-lg font-semibold">{agent.name}</h1>
                    <p className="text-muted-foreground text-sm">{agent.email}</p>
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <AgentStatusBreakdownCard breakdown={statusBreakdown} />
                    <TicketsTrendChart
                        data={completedTrend}
                        title="Tickets completed (last 30 days)"
                        emptyTitle="No tickets completed in this window"
                        valueLabel="Completed"
                    />
                </div>

                <FilterCard>
                    <MonthInput
                        name="month"
                        label="Month"
                        value={daily.month}
                        error={errors.month}
                        onChange={selectMonth}
                    />
                    {/* A plain <a>, not an Inertia <Link>: the response is a file download, not a page. */}
                    <Button asChild variant="outline">
                        <a href={dailyExport(agent.id, { query: { month: daily.month } }).url}>
                            <DownloadIcon />
                            Export CSV
                        </a>
                    </Button>
                </FilterCard>

                <AgentDailyTable daily={daily} />
            </div>
        </>
    );
}

AgentsShow.layout = (props: { agent: AgentSummary }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Agents', href: index() },
        { title: props.agent.name, href: show(props.agent.id) },
    ],
});
