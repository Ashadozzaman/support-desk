import { DataTable } from '@/components/data/DataTable';
import { PriorityBadge } from '@/components/ticket/PriorityBadge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatDay, formatMinutes } from '@/lib/format';
import type {
    DurationBreakdown,
    ResponseResolutionReport,
} from '@/types/report';
import type { TicketPriorityValue } from '@/types/ticket';

const PRIORITIES: TicketPriorityValue[] = ['critical', 'high', 'medium', 'low'];

function DurationTable({
    title,
    breakdown,
    description,
}: {
    title: string;
    breakdown: DurationBreakdown;
    description: string;
}) {
    const rows = [
        { key: 'all' as const, ...breakdown.all },
        ...PRIORITIES.map((priority) => ({
            key: priority,
            ...breakdown[priority],
        })),
    ];

    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                <CardDescription>{description}</CardDescription>
            </CardHeader>
            <CardContent>
                <DataTable
                    rows={rows}
                    rowKey={(row) => row.key}
                    columns={[
                        {
                            header: 'Priority',
                            cell: (row) =>
                                row.key === 'all' ? (
                                    'All priorities'
                                ) : (
                                    <PriorityBadge priority={row.key} />
                                ),
                        },
                        {
                            header: 'Average',
                            numeric: true,
                            cell: (row) => formatMinutes(row.avg),
                        },
                        {
                            header: 'Median',
                            numeric: true,
                            cell: (row) => formatMinutes(row.median),
                        },
                        {
                            header: '90th percentile',
                            numeric: true,
                            cell: (row) => formatMinutes(row.p90),
                        },
                        {
                            header: 'Tickets (median/p90)',
                            numeric: true,
                            cell: (row) => row.count.toLocaleString(),
                        },
                    ]}
                />
            </CardContent>
        </Card>
    );
}

/**
 * Durations are business minutes (only time inside business hours counts).
 * Averages cover the whole period; median/p90 are computed live over at
 * most the last N days of it — the window is shown so nobody compares a
 * 30-day median with a 90-day average without knowing.
 */
export function ResponseResolutionReportView({
    report,
    percentileMaxDays,
}: {
    report: ResponseResolutionReport;
    percentileMaxDays: number;
}) {
    const window = `Median and 90th percentile cover ${formatDay(report.percentileWindow.from)} – ${formatDay(
        report.percentileWindow.to,
    )} (at most the last ${percentileMaxDays} days). Times are business hours.`;

    return (
        <div className="flex flex-col gap-4">
            <DurationTable
                title="First response time"
                breakdown={report.firstResponse}
                description={window}
            />
            <DurationTable
                title="Resolution time"
                breakdown={report.resolution}
                description={window}
            />
        </div>
    );
}
