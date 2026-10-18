import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/data/EmptyState';

export type BreakdownRow = {
    key: string;
    label: string;
    count: number;
};

/**
 * Simple bar list shared by the by-priority and by-agent dashboard
 * breakdowns (ARCHITECTURE.md §14) — the two are the same shape, so one
 * component renders both rather than duplicating the markup.
 */
export function BreakdownCard({ title, rows }: { title: string; rows: BreakdownRow[] }) {
    const max = Math.max(1, ...rows.map((row) => row.count));

    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
            </CardHeader>
            <CardContent>
                {rows.length === 0 ? (
                    <EmptyState title="No tickets yet" />
                ) : (
                    <ul className="flex flex-col gap-3">
                        {rows.map((row) => (
                            <li key={row.key} className="flex items-center gap-3">
                                <span className="w-32 shrink-0 truncate text-sm">{row.label}</span>
                                <div className="bg-muted h-2 flex-1 overflow-hidden rounded-full">
                                    <div
                                        className="bg-primary h-full rounded-full"
                                        style={{ width: `${(row.count / max) * 100}%` }}
                                    />
                                </div>
                                <span className="text-muted-foreground w-8 text-right text-sm">{row.count}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}
