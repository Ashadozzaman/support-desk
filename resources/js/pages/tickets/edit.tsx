import { Head } from '@inertiajs/react';
import { TicketForm } from '@/components/ticket/TicketForm';
import { dashboard } from '@/routes';
import { edit, index, show } from '@/routes/tickets';
import type { CategoryOption, Ticket } from '@/types/ticket';

export default function TicketsEdit({
    ticket,
    categories,
}: {
    ticket: { data: Ticket };
    categories: CategoryOption[];
}) {
    return (
        <>
            <Head title={`Edit ${ticket.data.ticket_number}`} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <TicketForm mode="edit" ticket={ticket.data} categories={categories} />
            </div>
        </>
    );
}

TicketsEdit.layout = ({ ticket }: { ticket: { data: Ticket } }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Tickets', href: index() },
        { title: ticket.data.ticket_number, href: show(ticket.data.ticket_number) },
        { title: 'Edit', href: edit(ticket.data.ticket_number) },
    ],
});
