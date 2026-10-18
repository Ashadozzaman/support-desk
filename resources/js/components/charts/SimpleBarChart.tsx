import { Bar, BarChart, Cell, LabelList, XAxis } from 'recharts';
import { EmptyState } from '@/components/data/EmptyState';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';

export type BarDatum = {
    label: string;
    value: number;
    /** Defaults to the single-series --viz-trend blue. */
    color?: string;
};

/**
 * One measure across a few categories (magnitude). Every bar carries its
 * value as a direct label, so per-bar colors (when given, e.g. the priority
 * status ramp) are never the only way to read it.
 */
export function SimpleBarChart({
    title,
    data,
    valueLabel = 'Tickets',
    emptyTitle = 'Nothing to show',
}: {
    title: string;
    data: BarDatum[];
    valueLabel?: string;
    emptyTitle?: string;
}) {
    const total = data.reduce((sum, d) => sum + d.value, 0);

    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
            </CardHeader>
            <CardContent>
                {total === 0 ? (
                    <EmptyState title={emptyTitle} />
                ) : (
                    <ChartContainer className="aspect-auto h-56">
                        <BarChart data={data} margin={{ top: 20 }}>
                            <XAxis
                                dataKey="label"
                                tickLine={false}
                                axisLine={false}
                            />
                            <ChartTooltip
                                content={
                                    <ChartTooltipContent
                                        valueLabel={valueLabel}
                                    />
                                }
                                cursor={{ fill: 'transparent' }}
                            />
                            <Bar
                                dataKey="value"
                                radius={4}
                                isAnimationActive={false}
                            >
                                {data.map((d) => (
                                    <Cell
                                        key={d.label}
                                        fill={d.color ?? 'var(--viz-trend)'}
                                    />
                                ))}
                                <LabelList
                                    dataKey="value"
                                    position="top"
                                    className="fill-foreground text-xs"
                                    formatter={(value: unknown) =>
                                        Number(value).toLocaleString()
                                    }
                                />
                            </Bar>
                        </BarChart>
                    </ChartContainer>
                )}
            </CardContent>
        </Card>
    );
}
