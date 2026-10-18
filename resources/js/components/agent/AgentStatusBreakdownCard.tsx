import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { AgentStatusBreakdown } from '@/types/agent';

const ROWS: { key: keyof AgentStatusBreakdown; label: string }[] = [
    { key: 'open', label: 'Open' },
    { key: 'in_progress', label: 'In progress' },
    { key: 'waiting_for_customer', label: 'Waiting for customer' },
    { key: 'resolved', label: 'Resolved' },
    { key: 'closed', label: 'Closed' },
];

/**
 * All-time status breakdown for one agent (ARCHITECTURE.md §14) — unlike
 * the dashboard's by-agent widget, this includes resolved/closed so an
 * admin can see the agent's full history, not just current load.
 */
export function AgentStatusBreakdownCard({ breakdown }: { breakdown: AgentStatusBreakdown }) {
    const total = ROWS.reduce((sum, row) => sum + breakdown[row.key], 0);

    return (
        <Card>
            <CardHeader>
                <CardTitle>All-time status breakdown</CardTitle>
            </CardHeader>
            <CardContent>
                <ul className="flex flex-col gap-3">
                    {ROWS.map((row) => {
                        const count = breakdown[row.key];
                        const width = total === 0 ? 0 : (count / total) * 100;

                        return (
                            <li key={row.key} className="flex items-center gap-3">
                                <span className="w-44 shrink-0 truncate text-sm">{row.label}</span>
                                <div className="bg-muted h-2 flex-1 overflow-hidden rounded-full">
                                    <div className="bg-primary h-full rounded-full" style={{ width: `${width}%` }} />
                                </div>
                                <span className="text-muted-foreground w-8 text-right text-sm">{count}</span>
                            </li>
                        );
                    })}
                </ul>
            </CardContent>
        </Card>
    );
}
