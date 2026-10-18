import { SimpleBarChart } from '@/components/charts/SimpleBarChart';
import { DataTable } from '@/components/data/DataTable';
import { PriorityBadge } from '@/components/ticket/PriorityBadge';
import type { AgingReport } from '@/types/report';

/**
 * Live view of the open backlog by age (calendar time since creation).
 * Ignores the date range — it's "right now"; refreshed at most every minute.
 */
export function AgingReportView({ report }: { report: AgingReport }) {
    return (
        <div className="flex flex-col gap-4">
            <SimpleBarChart
                title="Open tickets by age (right now)"
                data={[
                    { label: '< 1 day', value: report.totals.age_0_1d },
                    { label: '1–3 days', value: report.totals.age_1_3d },
                    { label: '3–7 days', value: report.totals.age_3_7d },
                    { label: '7+ days', value: report.totals.age_7d_plus },
                ]}
                emptyTitle="No open tickets"
            />

            <DataTable
                rows={report.byPriority}
                rowKey={(row) => row.priority}
                columns={[
                    {
                        header: 'Priority',
                        cell: (row) => (
                            <PriorityBadge priority={row.priority} />
                        ),
                    },
                    {
                        header: '< 1 day',
                        numeric: true,
                        cell: (row) => row.age_0_1d.toLocaleString(),
                    },
                    {
                        header: '1–3 days',
                        numeric: true,
                        cell: (row) => row.age_1_3d.toLocaleString(),
                    },
                    {
                        header: '3–7 days',
                        numeric: true,
                        cell: (row) => row.age_3_7d.toLocaleString(),
                    },
                    {
                        header: '7+ days',
                        numeric: true,
                        cell: (row) => row.age_7d_plus.toLocaleString(),
                    },
                    {
                        header: 'Unassigned',
                        numeric: true,
                        cell: (row) => row.unassigned_count.toLocaleString(),
                    },
                    {
                        header: 'Oldest unassigned',
                        numeric: true,
                        cell: (row) =>
                            row.oldest_unassigned_at
                                ? new Date(
                                      `${row.oldest_unassigned_at.replace(' ', 'T')}Z`,
                                  ).toLocaleString()
                                : '—',
                    },
                ]}
            />
        </div>
    );
}
