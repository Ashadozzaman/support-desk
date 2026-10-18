import { Head, Link } from '@inertiajs/react';
import { PencilIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ActivityTimeline } from '@/components/activity/ActivityTimeline';
import { ReplyForm } from '@/components/reply/ReplyForm';
import { ReplyThread } from '@/components/reply/ReplyThread';
import { AssignAgentDialog } from '@/components/ticket/AssignAgentDialog';
import { ClaimTicketButton } from '@/components/ticket/ClaimTicketButton';
import { PriorityBadge } from '@/components/ticket/PriorityBadge';
import { SlaBadge } from '@/components/ticket/SlaBadge';
import { StatusBadge } from '@/components/ticket/StatusBadge';
import { useTicketChannel } from '@/hooks/use-ticket-channel';
import { dashboard } from '@/routes';
import { edit, index, show } from '@/routes/tickets';
import type { ReplyAbilities, ReplyItem } from '@/types/reply';
import type { ActivityItem, AgentOption, Ticket } from '@/types/ticket';

export default function TicketsShow({
    ticket,
    activities,
    replies,
    can,
    agents,
}: {
    ticket: { data: Ticket };
    activities: { data: ActivityItem[] };
    replies: { data: ReplyItem[] };
    can: ReplyAbilities;
    agents: AgentOption[];
}) {
    const t = ticket.data;

    useTicketChannel(t.ticket_number);

    return (
        <>
            <Head title={t.ticket_number} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <p className="text-muted-foreground font-mono text-sm">{t.ticket_number}</p>
                        <h1 className="text-xl font-semibold">{t.subject}</h1>
                    </div>
                    <div className="flex items-center gap-2">
                        <PriorityBadge priority={t.priority} />
                        <StatusBadge status={t.status} />
                        <SlaBadge ticket={t} />
                        {can.claim && <ClaimTicketButton ticketNumber={t.ticket_number} />}
                        {can.assign && (
                            <AssignAgentDialog
                                ticketNumber={t.ticket_number}
                                agents={agents}
                                reassign={t.assigned_agent !== null}
                            />
                        )}
                        {t.can.update && (
                            <Button asChild variant="outline" size="sm">
                                <Link href={edit(t.ticket_number).url}>
                                    <PencilIcon />
                                    Edit
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Description</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p className="whitespace-pre-wrap">{t.description}</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Details</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-2">
                        <Detail label="Category" value={t.category?.name ?? 'Uncategorised'} />
                        <Detail label="Assigned to" value={t.assigned_agent?.name ?? 'Unassigned'} />
                        <Detail label="Created by" value={t.creator.name} />
                        <Detail
                            label="Created"
                            value={new Date(t.created_at).toLocaleString()}
                        />
                        <Detail
                            label="SLA due"
                            value={t.sla_due_at ? new Date(t.sla_due_at).toLocaleString() : 'No SLA'}
                        />
                    </CardContent>
                </Card>

                <ReplyThread replies={replies.data} />

                {(can.reply || can.note) && <ReplyForm ticketNumber={t.ticket_number} canNote={can.note} />}

                <ActivityTimeline activities={activities.data} />
            </div>
        </>
    );
}

function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <p className="text-muted-foreground text-xs">{label}</p>
            <p className="text-sm font-medium">{value}</p>
        </div>
    );
}

TicketsShow.layout = (props: { ticket: { data: Ticket } }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Tickets', href: index() },
        { title: props.ticket.data.ticket_number, href: show(props.ticket.data.ticket_number) },
    ],
});
