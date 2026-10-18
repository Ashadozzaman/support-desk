import { Link } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import { AssignAgentDialog } from '@/components/ticket/AssignAgentDialog';
import { ClaimTicketButton } from '@/components/ticket/ClaimTicketButton';
import { PriorityBadge } from '@/components/ticket/PriorityBadge';
import { SlaBadge } from '@/components/ticket/SlaBadge';
import { StatusBadge } from '@/components/ticket/StatusBadge';
import { show } from '@/routes/tickets';
import type { AgentOption, TicketListItem } from '@/types/ticket';

export function TicketCard({ ticket, agents = [] }: { ticket: TicketListItem; agents?: AgentOption[] }) {
    const hasActions = ticket.can.claim || ticket.can.assign;

    return (
        <Card className="transition-colors hover:bg-accent/50">
            <CardContent className="space-y-2">
                <Link href={show(ticket.ticket_number).url} className="block space-y-2">
                    <div className="flex items-center justify-between gap-2">
                        <span className="text-muted-foreground font-mono text-xs">
                            {ticket.ticket_number}
                        </span>
                        <div className="flex flex-wrap justify-end gap-1.5">
                            <PriorityBadge priority={ticket.priority} />
                            <StatusBadge status={ticket.status} />
                            <SlaBadge ticket={ticket} />
                        </div>
                    </div>
                    <p className="font-medium">{ticket.subject}</p>
                    <div className="text-muted-foreground flex items-center justify-between text-xs">
                        <span>{ticket.category?.name ?? 'Uncategorised'}</span>
                        <span>{ticket.assigned_agent?.name ?? 'Unassigned'}</span>
                    </div>
                </Link>
                {hasActions && (
                    <div className="flex items-center gap-1.5 pt-1">
                        {ticket.can.claim && <ClaimTicketButton ticketNumber={ticket.ticket_number} />}
                        {ticket.can.assign && (
                            <AssignAgentDialog
                                ticketNumber={ticket.ticket_number}
                                agents={agents}
                                reassign={ticket.assigned_agent !== null}
                            />
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
