import { Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { show } from '@/routes/admin/agents';
import type { AgentPerformanceSummary } from '@/types/agent';

/**
 * Admin-only agent list (ARCHITECTURE.md §14.1) — every agent, even one with
 * zero tickets. Workload columns are live (as of now); "Completed" counts
 * tickets resolved/closed inside the page's selected date range.
 */
export function AgentPerformanceTable({ agents }: { agents: AgentPerformanceSummary[] }) {
    return (
        <>
            <div className="hidden overflow-x-auto rounded-lg border md:block">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-muted-foreground text-left">
                        <tr>
                            <th className="px-4 py-2 font-medium">Agent</th>
                            <th className="px-4 py-2 font-medium">Completed</th>
                            <th className="px-4 py-2 font-medium">Active now</th>
                            <th className="px-4 py-2 font-medium">Open</th>
                            <th className="px-4 py-2 font-medium">In progress</th>
                            <th className="px-4 py-2 font-medium">Waiting</th>
                            <th className="px-4 py-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        {agents.map((row) => (
                            <tr key={row.agent.id} className="border-t">
                                <td className="px-4 py-2 font-medium">
                                    <Link href={show(row.agent.id).url} className="hover:underline">
                                        {row.agent.name}
                                    </Link>
                                </td>
                                <td className="px-4 py-2 font-medium">{row.completed_count}</td>
                                <td className="px-4 py-2">
                                    {row.active_count}/{row.max_active_tickets}
                                </td>
                                <td className="px-4 py-2">{row.open_count}</td>
                                <td className="px-4 py-2">{row.in_progress_count}</td>
                                <td className="px-4 py-2">{row.waiting_count}</td>
                                <td className="px-4 py-2">
                                    <div className="flex flex-wrap gap-1.5">
                                        {!row.is_available && <Badge variant="secondary">Unavailable</Badge>}
                                        {row.stuck_count > 0 && (
                                            <Badge variant="destructive">{row.stuck_count} stuck</Badge>
                                        )}
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="grid gap-3 md:hidden">
                {agents.map((row) => (
                    <div key={row.agent.id} className="flex flex-col gap-2 rounded-lg border p-4">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <Link href={show(row.agent.id).url} className="font-medium hover:underline">
                                {row.agent.name}
                            </Link>
                            <span className="text-muted-foreground text-sm">
                                {row.active_count}/{row.max_active_tickets} active
                            </span>
                        </div>
                        <div className="flex flex-wrap gap-1.5">
                            {!row.is_available && <Badge variant="secondary">Unavailable</Badge>}
                            {row.stuck_count > 0 && <Badge variant="destructive">{row.stuck_count} stuck</Badge>}
                        </div>
                        <div className="text-muted-foreground flex flex-wrap gap-x-4 gap-y-1 text-xs">
                            <span className="text-foreground font-medium">{row.completed_count} completed</span>
                            <span>{row.open_count} open</span>
                            <span>{row.in_progress_count} in progress</span>
                            <span>{row.waiting_count} waiting</span>
                        </div>
                    </div>
                ))}
            </div>
        </>
    );
}
