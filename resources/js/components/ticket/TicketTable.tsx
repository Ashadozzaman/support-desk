import { Link } from '@inertiajs/react';
import { TicketCard } from '@/components/cards/TicketCard';
import { AssignAgentDialog } from '@/components/ticket/AssignAgentDialog';
import { ClaimTicketButton } from '@/components/ticket/ClaimTicketButton';
import { PriorityBadge } from '@/components/ticket/PriorityBadge';
import { SlaBadge } from '@/components/ticket/SlaBadge';
import { StatusBadge } from '@/components/ticket/StatusBadge';
import { show } from '@/routes/tickets';
import type { AgentOption, TicketListItem } from '@/types/ticket';

export function TicketTable({ tickets, agents }: { tickets: TicketListItem[]; agents: AgentOption[] }) {
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
                            <th className="px-4 py-2 font-medium">SLA</th>
                            <th className="px-4 py-2 font-medium">Category</th>
                            <th className="px-4 py-2 font-medium">Assigned to</th>
                            <th className="px-4 py-2 font-medium">Created</th>
                            <th className="px-4 py-2 font-medium">Actions</th>
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
                                <td className="px-4 py-2">
                                    <SlaBadge ticket={ticket} />
                                </td>
                                <td className="px-4 py-2">{ticket.category?.name ?? '—'}</td>
                                <td className="px-4 py-2">
                                    {ticket.assigned_agent?.name ?? 'Unassigned'}
                                </td>
                                <td className="text-muted-foreground px-4 py-2">
                                    {new Date(ticket.created_at).toLocaleDateString()}
                                </td>
                                <td className="px-4 py-2">
                                    <div className="flex items-center gap-1.5">
                                        {ticket.can.claim && (
                                            <ClaimTicketButton ticketNumber={ticket.ticket_number} />
                                        )}
                                        {ticket.can.assign && (
                                            <AssignAgentDialog
                                                ticketNumber={ticket.ticket_number}
                                                agents={agents}
                                                reassign={ticket.assigned_agent !== null}
                                            />
                                        )}
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="grid gap-3 md:hidden">
                {tickets.map((ticket) => (
                    <TicketCard key={ticket.ticket_number} ticket={ticket} agents={agents} />
                ))}
            </div>
        </>
    );
}
