import { Head } from '@inertiajs/react';
import { TicketForm } from '@/components/ticket/TicketForm';
import { dashboard } from '@/routes';
import { create, index } from '@/routes/tickets';
import type { CategoryOption } from '@/types/ticket';

export default function TicketsCreate({ categories }: { categories: CategoryOption[] }) {
    return (
        <>
            <Head title="New ticket" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <TicketForm mode="create" categories={categories} />
            </div>
        </>
    );
}

TicketsCreate.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Tickets', href: index() },
        { title: 'New ticket', href: create() },
    ],
};
