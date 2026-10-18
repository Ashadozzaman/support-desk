import { Head, router, usePage } from '@inertiajs/react';
import { AgentPerformanceTable } from '@/components/agent/AgentPerformanceTable';
import { FilterCard } from '@/components/cards/FilterCard';
import { EmptyState } from '@/components/data/EmptyState';
import { Pagination } from '@/components/data/Pagination';
import { DateRangeInput } from '@/components/form/DateRangeInput';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { index } from '@/routes/admin/agents';
import type { AgentPerformanceFilters, AgentPerformanceSummary } from '@/types/agent';
import type { Paginated } from '@/types/pagination';

/**
 * `today` comes from the server (business timezone), so presets never
 * drift with the admin's browser clock. Plain UTC date maths on the
 * `YYYY-MM-DD` string avoids local-timezone off-by-one days.
 */
function daysBefore(today: string, days: number): string {
    const date = new Date(`${today}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() - days);

    return date.toISOString().slice(0, 10);
}

export default function AgentsIndex({
    agents,
    filters,
    today,
}: {
    agents: Paginated<AgentPerformanceSummary>;
    filters: AgentPerformanceFilters;
    today: string;
}) {
    const { errors } = usePage().props;

    function applyRange(from: string | null, to: string | null) {
        router.get(
            index({ query: { from, to } }).url,
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    const presets = [
        { label: 'Today', from: today },
        { label: 'Last 7 days', from: daysBefore(today, 6) },
        { label: 'Last 30 days', from: daysBefore(today, 29) },
    ];

    return (
        <>
            <Head title="Agents" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-lg font-semibold">Agents</h1>
                    <p className="text-muted-foreground text-sm">
                        "Completed" counts tickets each agent resolved or closed in the selected dates; the other
                        columns are their workload right now. Click a name for their full status history.
                    </p>
                </div>

                <FilterCard>
                    <DateRangeInput
                        fromName="from"
                        toName="to"
                        label="Completed between"
                        from={filters.from}
                        to={filters.to}
                        errors={{ from: errors.from, to: errors.to }}
                        onChange={(range) => applyRange(range.from, range.to)}
                    />
                    <div className="flex flex-wrap gap-2">
                        {presets.map((preset) => (
                            <Button
                                key={preset.label}
                                type="button"
                                size="sm"
                                variant={filters.from === preset.from && filters.to === today ? 'default' : 'outline'}
                                onClick={() => applyRange(preset.from, today)}
                            >
                                {preset.label}
                            </Button>
                        ))}
                    </div>
                </FilterCard>

                {agents.data.length === 0 ? (
                    <EmptyState title="No agents yet" />
                ) : (
                    <>
                        <AgentPerformanceTable agents={agents.data} />
                        <Pagination meta={agents.meta} />
                    </>
                )}
            </div>
        </>
    );
}

AgentsIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Agents', href: index() },
    ],
};
