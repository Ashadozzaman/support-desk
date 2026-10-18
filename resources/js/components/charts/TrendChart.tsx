import {
    CartesianGrid,
    LabelList,
    Line,
    LineChart,
    XAxis,
    YAxis,
} from 'recharts';
import { EmptyState } from '@/components/data/EmptyState';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';

export type TrendSeries = {
    /** Key of the value in each row. */
    key: string;
    label: string;
    /** A CSS color — use the --viz-* tokens so dark mode follows. */
    color: string;
};

type Row = { label: string } & Record<string, number | string | null>;

/**
 * Change over time for one or more series on ONE y-axis (never dual-axis).
 * Two or more series get a legend AND a direct label at the line's end, so
 * identity never rests on color alone; one series needs neither (the title
 * names it). 2px lines, no area fill, so overlapping series stay readable;
 * the tooltip is the hover layer.
 */
export function TrendChart({
    title,
    rows,
    series,
    emptyTitle = 'Nothing in this period',
    formatValue,
    yDomain,
}: {
    title: string;
    rows: Row[];
    series: TrendSeries[];
    emptyTitle?: string;
    formatValue?: (value: number | string | undefined) => string;
    yDomain?: [number, number];
}) {
    const hasData = rows.some((row) =>
        series.some((s) => typeof row[s.key] === 'number' && row[s.key] !== 0),
    );
    const lastIndex = rows.length - 1;

    return (
        <Card>
            <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2">
                <CardTitle>{title}</CardTitle>
                {series.length > 1 && (
                    <div className="flex flex-wrap gap-3 text-xs">
                        {series.map((s) => (
                            <span
                                key={s.key}
                                className="flex items-center gap-1.5 text-muted-foreground"
                            >
                                <span
                                    className="h-0.5 w-3 rounded-full"
                                    style={{ backgroundColor: s.color }}
                                />
                                {s.label}
                            </span>
                        ))}
                    </div>
                )}
            </CardHeader>
            <CardContent>
                {!hasData ? (
                    <EmptyState title={emptyTitle} />
                ) : (
                    <ChartContainer className="aspect-auto h-64">
                        <LineChart
                            data={rows}
                            margin={{
                                left: 0,
                                right: series.length > 1 ? 64 : 12,
                                top: 12,
                            }}
                        >
                            <CartesianGrid
                                vertical={false}
                                strokeOpacity={0.5}
                            />
                            <XAxis
                                dataKey="label"
                                tickLine={false}
                                axisLine={false}
                                minTickGap={24}
                            />
                            <YAxis
                                tickLine={false}
                                axisLine={false}
                                width={40}
                                domain={yDomain}
                            />
                            <ChartTooltip
                                content={
                                    <ChartTooltipContent
                                        formatValue={formatValue}
                                    />
                                }
                            />
                            {series.map((s) => (
                                <Line
                                    key={s.key}
                                    dataKey={s.key}
                                    name={s.label}
                                    type="monotone"
                                    stroke={s.color}
                                    strokeWidth={2}
                                    dot={false}
                                    activeDot={{ r: 4 }}
                                    connectNulls
                                    isAnimationActive={false}
                                >
                                    {series.length > 1 && (
                                        <LabelList
                                            dataKey={s.key}
                                            content={({ x, y, index }) =>
                                                index === lastIndex ? (
                                                    <text
                                                        x={Number(x) + 6}
                                                        y={Number(y)}
                                                        dy={4}
                                                        className="fill-muted-foreground text-xs"
                                                    >
                                                        {s.label}
                                                    </text>
                                                ) : null
                                            }
                                        />
                                    )}
                                </Line>
                            ))}
                        </LineChart>
                    </ChartContainer>
                )}
            </CardContent>
        </Card>
    );
}
