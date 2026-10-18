import * as React from 'react';
import * as RechartsPrimitive from 'recharts';
import { cn } from '@/lib/utils';

/**
 * A deliberately small subset of shadcn/ui's chart component — just the
 * ResponsiveContainer wrapper (with Recharts' default outlines tamed) plus
 * a tooltip. The full upstream version injects per-series colors via a
 * generated `<style>` tag (`dangerouslySetInnerHTML`), which CLAUDE.md's
 * "no dangerouslySetInnerHTML" rule rules out here — this project's charts
 * pass colors straight to Recharts' `fill`/`stroke` props instead, so that
 * injection step isn't needed.
 */
function ChartContainer({
    className,
    children,
    ...props
}: React.ComponentProps<'div'> & {
    children: React.ComponentProps<typeof RechartsPrimitive.ResponsiveContainer>['children'];
}) {
    return (
        <div
            data-slot="chart"
            className={cn(
                "[&_.recharts-cartesian-axis-tick_text]:fill-muted-foreground [&_.recharts-cartesian-grid_line]:stroke-border/50 [&_.recharts-curve.recharts-tooltip-cursor]:stroke-border flex aspect-video justify-center text-xs [&_.recharts-layer]:outline-hidden [&_.recharts-rectangle.recharts-tooltip-cursor]:fill-muted [&_.recharts-sector]:outline-hidden [&_.recharts-surface]:outline-hidden",
                className,
            )}
            {...props}
        >
            <RechartsPrimitive.ResponsiveContainer>{children}</RechartsPrimitive.ResponsiveContainer>
        </div>
    );
}

const ChartTooltip = RechartsPrimitive.Tooltip;

function ChartTooltipContent({
    active,
    payload,
    label,
    valueLabel,
}: {
    active?: boolean;
    payload?: ReadonlyArray<{ value?: number | string; payload?: Record<string, unknown> }>;
    label?: string;
    valueLabel?: string;
}) {
    if (!active || !payload?.length) {
        return null;
    }

    return (
        <div className="bg-background grid min-w-32 gap-1 rounded-lg border px-3 py-2 text-xs shadow-md">
            <p className="font-medium">{label}</p>
            <p className="text-muted-foreground flex items-center justify-between gap-2">
                <span>{valueLabel ?? 'Count'}</span>
                <span className="text-foreground font-mono font-medium">{payload[0].value}</span>
            </p>
        </div>
    );
}

export { ChartContainer, ChartTooltip, ChartTooltipContent };
