import {
    AlertTriangleIcon,
    InboxIcon,
    RotateCcwIcon,
    TicketCheckIcon,
    TicketPlusIcon,
    UserXIcon,
} from 'lucide-react';
import { StatCard } from '@/components/cards/StatCard';
import { TrendChart } from '@/components/charts/TrendChart';
import { formatBucket, formatDay } from '@/lib/format';
import type { OverviewReport, ReportGranularity } from '@/types/report';

export function OverviewReportView({
    report,
    granularity,
}: {
    report: OverviewReport;
    granularity: ReportGranularity;
}) {
    return (
        <div className="flex flex-col gap-4">
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <StatCard
                    label="Created in period"
                    value={report.totals.created.toLocaleString()}
                    icon={TicketPlusIcon}
                />
                <StatCard
                    label="Resolved in period"
                    value={report.totals.resolved.toLocaleString()}
                    icon={TicketCheckIcon}
                />
                <StatCard
                    label="Reopened in period"
                    value={report.totals.reopened.toLocaleString()}
                    icon={RotateCcwIcon}
                />
                <StatCard
                    label="Open right now"
                    value={report.now.open_count.toLocaleString()}
                    icon={InboxIcon}
                />
                <StatCard
                    label="Unassigned right now"
                    value={report.now.unassigned_count.toLocaleString()}
                    icon={UserXIcon}
                />
                <StatCard
                    label="Past SLA right now"
                    value={report.now.breached_open_count.toLocaleString()}
                    icon={AlertTriangleIcon}
                />
            </div>

            <TrendChart
                title="Created vs resolved"
                rows={report.series.map((point) => ({
                    label: formatBucket(point.bucket, granularity),
                    created: point.created,
                    resolved: point.resolved,
                }))}
                series={[
                    {
                        key: 'created',
                        label: 'Created',
                        color: 'var(--viz-series-1)',
                    },
                    {
                        key: 'resolved',
                        label: 'Resolved',
                        color: 'var(--viz-series-2)',
                    },
                ]}
                emptyTitle="No tickets created or resolved in this period"
            />

            <TrendChart
                title="Open backlog at end of day"
                rows={report.backlog.map((day) => ({
                    label: formatDay(day.stat_date),
                    open: day.open_count,
                }))}
                series={[
                    { key: 'open', label: 'Open', color: 'var(--viz-trend)' },
                ]}
                emptyTitle="No snapshots yet — reports:snapshot records one each night"
            />
        </div>
    );
}
