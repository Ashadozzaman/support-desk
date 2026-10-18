import { Bar, BarChart, Cell, LabelList, XAxis } from 'recharts';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ChartContainer, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';
import { EmptyState } from '@/components/data/EmptyState';
import { PRIORITY_CHART_COLORS } from '@/lib/chart-colors';
import { TICKET_PRIORITY_LABELS } from '@/types/ticket';
import type { PriorityBreakdown } from '@/types/dashboard';

/**
 * Bars are colored by the dataviz skill's status ramp (severity, not a
 * generic categorical palette) — every bar also carries the priority name
 * on the axis and its count as a direct label, since that pairing is the
 * documented mitigation for how close two of those status hues sit.
 */
export function PriorityBarChart({ data }: { data: PriorityBreakdown[] }) {
    const rows = data.map((row) => ({
        priority: row.priority,
        label: TICKET_PRIORITY_LABELS[row.priority],
        count: row.count,
    }));

    return (
        <Card>
            <CardHeader>
                <CardTitle>By priority</CardTitle>
            </CardHeader>
            <CardContent>
                {rows.length === 0 ? (
                    <EmptyState title="No tickets yet" />
                ) : (
                    <ChartContainer className="aspect-auto h-56">
                        <BarChart data={rows} margin={{ top: 20 }}>
                            <XAxis dataKey="label" tickLine={false} axisLine={false} />
                            <ChartTooltip
                                content={<ChartTooltipContent valueLabel="Tickets" />}
                                cursor={{ fill: 'transparent' }}
                            />
                            <Bar dataKey="count" radius={4}>
                                {rows.map((row) => (
                                    <Cell key={row.priority} fill={PRIORITY_CHART_COLORS[row.priority]} />
                                ))}
                                <LabelList dataKey="count" position="top" className="fill-foreground text-xs" />
                            </Bar>
                        </BarChart>
                    </ChartContainer>
                )}
            </CardContent>
        </Card>
    );
}
