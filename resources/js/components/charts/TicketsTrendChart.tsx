import { Area, AreaChart, CartesianGrid, XAxis } from 'recharts';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ChartContainer, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';
import { EmptyState } from '@/components/data/EmptyState';
import type { TicketsTrendPoint } from '@/types/dashboard';

function formatDay(day: string): string {
    return new Date(day).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

/**
 * Single series over time — the dataviz skill's sequential-blue default
 * (`--viz-trend`) is enough on its own here; a lone series needs no legend
 * or categorical palette.
 */
export function TicketsTrendChart({ data }: { data: TicketsTrendPoint[] }) {
    const total = data.reduce((sum, point) => sum + point.count, 0);
    const rows = data.map((point) => ({ ...point, label: formatDay(point.day) }));

    return (
        <Card>
            <CardHeader>
                <CardTitle>Tickets created (last 14 days)</CardTitle>
            </CardHeader>
            <CardContent>
                {total === 0 ? (
                    <EmptyState title="No tickets created in this window" />
                ) : (
                    <ChartContainer className="aspect-auto h-56">
                        <AreaChart data={rows} margin={{ left: 0, right: 0, top: 10 }}>
                            <CartesianGrid vertical={false} />
                            <XAxis dataKey="label" tickLine={false} axisLine={false} interval={1} />
                            <ChartTooltip content={<ChartTooltipContent valueLabel="Tickets" />} />
                            <Area
                                dataKey="count"
                                type="monotone"
                                stroke="var(--viz-trend)"
                                fill="var(--viz-trend)"
                                fillOpacity={0.15}
                                strokeWidth={2}
                            />
                        </AreaChart>
                    </ChartContainer>
                )}
            </CardContent>
        </Card>
    );
}
