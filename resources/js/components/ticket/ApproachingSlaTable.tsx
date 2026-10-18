import { Link } from '@inertiajs/react';
import { EmptyState } from '@/components/data/EmptyState';
import { PriorityBadge } from '@/components/ticket/PriorityBadge';
import { SlaBadge } from '@/components/ticket/SlaBadge';
import { StatusBadge } from '@/components/ticket/StatusBadge';
import { show } from '@/routes/tickets';
import type { TicketListItem } from '@/types/ticket';

/**
 * Read-only "approaching SLA" list for the dashboard (ARCHITECTURE.md §14)
 * — unlike `TicketTable` it has no claim/assign actions, so it gets its
 * own component rather than reusing that one with unused props.
 */
export function ApproachingSlaTable({ tickets }: { tickets: TicketListItem[] }) {
    if (tickets.length === 0) {
        return <EmptyState title="Nothing approaching its SLA deadline" />;
    }

    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th className="px-4 py-2 font-medium">Ticket</th>
                        <th className="px-4 py-2 font-medium">Subject</th>
                        <th className="px-4 py-2 font-medium">Status</th>
                        <th className="px-4 py-2 font-medium">Priority</th>
                        <th className="px-4 py-2 font-medium">SLA</th>
                        <th className="px-4 py-2 font-medium">Assigned to</th>
                    </tr>
                </thead>
                <tbody>
                    {tickets.map((ticket) => (
                        <tr key={ticket.ticket_number} className="border-t">
                            <td className="px-4 py-2 font-mono text-xs">
                                <Link href={show(ticket.ticket_number).url} className="hover:underline">
                                    {ticket.ticket_number}
                                </Link>
                            </td>
                            <td className="max-w-xs truncate px-4 py-2">{ticket.subject}</td>
                            <td className="px-4 py-2">
                                <StatusBadge status={ticket.status} />
                            </td>
                            <td className="px-4 py-2">
                                <PriorityBadge priority={ticket.priority} />
                            </td>
                            <td className="px-4 py-2">
                                <SlaBadge ticket={ticket} />
                            </td>
                            <td className="px-4 py-2">{ticket.assigned_agent?.name ?? 'Unassigned'}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
