import { DataTable } from '@/components/data/DataTable';
import { EmptyState } from '@/components/data/EmptyState';
import { formatMinutes, formatPercent } from '@/lib/format';
import type { AgentReportRow } from '@/types/report';

/**
 * One row per agent with activity in the period; an agent (reports.view-own)
 * only ever receives their own row from the server.
 */
export function AgentsReportView({ rows }: { rows: AgentReportRow[] }) {
    if (rows.length === 0) {
        return <EmptyState title="No agent activity in this period" />;
    }

    return (
        <DataTable
            rows={rows}
            rowKey={(row) => row.agent_id}
            columns={[
                {
                    header: 'Agent',
                    cell: (row) => row.name ?? `#${row.agent_id}`,
                },
                {
                    header: 'Resolved',
                    numeric: true,
                    cell: (row) => row.resolved.toLocaleString(),
                },
                {
                    header: 'SLA met',
                    numeric: true,
                    cell: (row) => formatPercent(row.sla_met_pct),
                },
                {
                    header: 'Avg first response',
                    numeric: true,
                    cell: (row) =>
                        formatMinutes(row.avg_first_response_minutes),
                },
                {
                    header: 'Avg resolution',
                    numeric: true,
                    cell: (row) => formatMinutes(row.avg_resolution_minutes),
                },
                {
                    header: 'Reopen rate',
                    numeric: true,
                    cell: (row) => formatPercent(row.reopen_rate_pct),
                },
                {
                    header: 'Escalations',
                    numeric: true,
                    cell: (row) => row.escalated.toLocaleString(),
                },
                {
                    header: 'Active now',
                    numeric: true,
                    cell: (row) => row.active_now.toLocaleString(),
                },
            ]}
        />
    );
}
