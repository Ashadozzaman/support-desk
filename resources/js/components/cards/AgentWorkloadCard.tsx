import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/data/EmptyState';
import type { AgentBreakdown } from '@/types/dashboard';

/**
 * Per-agent performance row for the admin dashboard (ARCHITECTURE.md §14):
 * current load broken down by status, a "stuck" warning for active tickets
 * assigned longer than `support.dashboard.stuck_after_days`, and recent
 * throughput — not just a bare workload count/progress bar.
 */
export function AgentWorkloadCard({ rows }: { rows: AgentBreakdown[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>By agent</CardTitle>
            </CardHeader>
            <CardContent>
                {rows.length === 0 ? (
                    <EmptyState title="No tickets yet" />
                ) : (
                    <ul className="flex max-h-96 flex-col gap-4 overflow-y-auto pr-2">
                        {rows.map((row) => {
                            const key = row.agent === null ? 'unassigned' : String(row.agent.id);
                            const label = row.agent?.name ?? 'Unassigned';
                            const capacity =
                                row.agent !== null && row.max_active_tickets !== null
                                    ? `${row.active_count}/${row.max_active_tickets}`
                                    : null;

                            return (
                                <li key={key} className="flex flex-col gap-1.5 border-b pb-3 last:border-0 last:pb-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-sm font-medium">{label}</span>
                                        {capacity !== null && (
                                            <span className="text-muted-foreground text-xs">{capacity} active</span>
                                        )}
                                        {row.agent !== null && row.is_available === false && (
                                            <Badge variant="secondary">Unavailable</Badge>
                                        )}
                                        {row.stuck_count > 0 && (
                                            <Badge variant="destructive">{row.stuck_count} stuck</Badge>
                                        )}
                                    </div>
                                    <div className="text-muted-foreground flex flex-wrap gap-x-4 gap-y-1 text-xs">
                                        <span>{row.open_count} open</span>
                                        <span>{row.in_progress_count} in progress</span>
                                        <span>{row.waiting_count} waiting</span>
                                        <span>{row.resolved_7d_count} resolved (7d)</span>
                                        <span>{row.resolved_30d_count} resolved (30d)</span>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}
