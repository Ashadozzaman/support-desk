import { DataTable } from '@/components/data/DataTable';
import { EmptyState } from '@/components/data/EmptyState';
import { formatMinutes, formatPercent } from '@/lib/format';
import type { CategoryReportRow } from '@/types/report';

export function CategoriesReportView({ rows }: { rows: CategoryReportRow[] }) {
    if (rows.length === 0) {
        return <EmptyState title="No tickets in this period" />;
    }

    return (
        <DataTable
            rows={rows}
            rowKey={(row) => row.category_id}
            columns={[
                {
                    header: 'Category',
                    cell: (row) => row.name ?? 'No category',
                },
                {
                    header: 'Created',
                    numeric: true,
                    cell: (row) => row.created.toLocaleString(),
                },
                {
                    header: 'Share',
                    numeric: true,
                    cell: (row) => formatPercent(row.share_pct),
                },
                {
                    header: 'Resolved',
                    numeric: true,
                    cell: (row) => row.resolved.toLocaleString(),
                },
                {
                    header: 'Avg resolution',
                    numeric: true,
                    cell: (row) => formatMinutes(row.avg_resolution_minutes),
                },
                {
                    header: 'Breach rate',
                    numeric: true,
                    cell: (row) => formatPercent(row.breach_rate_pct),
                },
            ]}
        />
    );
}
