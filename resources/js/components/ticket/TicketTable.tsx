import { Link } from '@inertiajs/react';
import { TicketCard } from '@/components/cards/TicketCard';
import { PriorityBadge } from '@/components/ticket/PriorityBadge';
import { StatusBadge } from '@/components/ticket/StatusBadge';
import { show } from '@/routes/tickets';
import type { TicketListItem } from '@/types/ticket';

export function TicketTable({ tickets }: { tickets: TicketListItem[] }) {
    return (
        <>
            <div className="hidden overflow-x-auto rounded-lg border md:block">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-muted-foreground text-left">
                        <tr>
                            <th className="px-4 py-2 font-medium">Ticket</th>
                            <th className="px-4 py-2 font-medium">Subject</th>
                            <th className="px-4 py-2 font-medium">Status</th>
                            <th className="px-4 py-2 font-medium">Priority</th>
                            <th className="px-4 py-2 font-medium">Category</th>
                            <th className="px-4 py-2 font-medium">Assigned to</th>
                            <th className="px-4 py-2 font-medium">Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        {tickets.map((ticket) => (
                            <tr key={ticket.ticket_number} className="border-t">
                                <td className="px-4 py-2 font-mono text-xs">
                                    <Link
                                        href={show(ticket.ticket_number).url}
                                        className="hover:underline"
                                    >
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
                                <td className="px-4 py-2">{ticket.category?.name ?? '—'}</td>
                                <td className="px-4 py-2">
                                    {ticket.assigned_agent?.name ?? 'Unassigned'}
                                </td>
                                <td className="text-muted-foreground px-4 py-2">
                                    {new Date(ticket.created_at).toLocaleDateString()}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="grid gap-3 md:hidden">
                {tickets.map((ticket) => (
                    <TicketCard key={ticket.ticket_number} ticket={ticket} />
                ))}
            </div>
        </>
    );
}
