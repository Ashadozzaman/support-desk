import {
    AlertTriangleIcon,
    CheckCircle2Icon,
    PercentIcon,
    XCircleIcon,
} from 'lucide-react';
import { StatCard } from '@/components/cards/StatCard';
import { TrendChart } from '@/components/charts/TrendChart';
import { DataTable } from '@/components/data/DataTable';
import { PriorityBadge } from '@/components/ticket/PriorityBadge';
import { formatBucket, formatPercent } from '@/lib/format';
import type { ReportGranularity, SlaReport } from '@/types/report';

/**
 * Compliance = met / (met + breached) of tickets resolved in the period;
 * resolved tickets without an SLA count in neither.
 */
export function SlaReportView({
    report,
    granularity,
}: {
    report: SlaReport;
    granularity: ReportGranularity;
}) {
    return (
        <div className="flex flex-col gap-4">
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label="Compliance"
                    value={formatPercent(report.totals.compliance_pct)}
                    icon={PercentIcon}
                />
                <StatCard
                    label="Resolved within SLA"
                    value={report.totals.met.toLocaleString()}
                    icon={CheckCircle2Icon}
                />
                <StatCard
                    label="Resolved late"
                    value={report.totals.breached.toLocaleString()}
                    icon={XCircleIcon}
                />
                <StatCard
                    label="Escalations"
                    value={report.totals.escalated.toLocaleString()}
                    icon={AlertTriangleIcon}
                />
            </div>

            <TrendChart
                title="SLA compliance %"
                rows={report.series.map((point) => ({
                    label: formatBucket(point.bucket, granularity),
                    compliance: point.compliance_pct,
                }))}
                series={[
                    {
                        key: 'compliance',
                        label: 'Compliance',
                        color: 'var(--viz-trend)',
                    },
                ]}
                yDomain={[0, 100]}
                formatValue={(value) =>
                    typeof value === 'number' ? formatPercent(value) : '—'
                }
                emptyTitle="No tickets with an SLA were resolved in this period"
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
                        header: 'Within SLA',
                        numeric: true,
                        cell: (row) => row.met.toLocaleString(),
                    },
                    {
                        header: 'Late',
                        numeric: true,
                        cell: (row) => row.breached.toLocaleString(),
                    },
                    {
                        header: 'Compliance',
                        numeric: true,
                        cell: (row) => formatPercent(row.compliance_pct),
                    },
                ]}
            />
        </div>
    );
}
