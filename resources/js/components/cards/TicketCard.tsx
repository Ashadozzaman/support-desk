import { Link } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import { PriorityBadge } from '@/components/ticket/PriorityBadge';
import { StatusBadge } from '@/components/ticket/StatusBadge';
import { show } from '@/routes/tickets';
import type { TicketListItem } from '@/types/ticket';

export function TicketCard({ ticket }: { ticket: TicketListItem }) {
    return (
        <Link href={show(ticket.ticket_number).url}>
            <Card className="transition-colors hover:bg-accent/50">
                <CardContent className="space-y-2">
                    <div className="flex items-center justify-between gap-2">
                        <span className="text-muted-foreground font-mono text-xs">
                            {ticket.ticket_number}
                        </span>
                        <div className="flex gap-1.5">
                            <PriorityBadge priority={ticket.priority} />
                            <StatusBadge status={ticket.status} />
                        </div>
                    </div>
                    <p className="font-medium">{ticket.subject}</p>
                    <div className="text-muted-foreground flex items-center justify-between text-xs">
                        <span>{ticket.category?.name ?? 'Uncategorised'}</span>
                        <span>{ticket.assigned_agent?.name ?? 'Unassigned'}</span>
                    </div>
                </CardContent>
            </Card>
        </Link>
    );
}
