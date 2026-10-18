import { Head, Link } from '@inertiajs/react';
import { PlusIcon, TicketIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/data/EmptyState';
import { Pagination } from '@/components/data/Pagination';
import { TicketTable } from '@/components/ticket/TicketTable';
import { useStaffQueue } from '@/hooks/use-staff-queue';
import { dashboard } from '@/routes';
import { create, index } from '@/routes/tickets';
import type { Paginated } from '@/types/pagination';
import type { AgentOption, TicketListItem } from '@/types/ticket';

type Tab = 'active' | 'resolved';

export default function TicketsIndex({
    tickets,
    tab,
    agents,
}: {
    tickets: Paginated<TicketListItem>;
    tab: Tab;
    agents: AgentOption[];
}) {
    useStaffQueue(['tickets']);

    const tabs: { value: Tab; label: string }[] = [
        { value: 'active', label: 'Active' },
        { value: 'resolved', label: 'Resolved' },
    ];

    return (
        <>
            <Head title="Tickets" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-semibold">Tickets</h1>
                    <Button asChild>
                        <Link href={create().url}>
                            <PlusIcon />
                            New ticket
                        </Link>
                    </Button>
                </div>

                <div className="flex gap-1 border-b">
                    {tabs.map((item) => (
                        <Link
                            key={item.value}
                            href={index({ query: { tab: item.value } }).url}
                            className={`border-b-2 px-3 py-2 text-sm font-medium ${
                                tab === item.value
                                    ? 'border-primary text-foreground'
                                    : 'border-transparent text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            {item.label}
                        </Link>
                    ))}
                </div>

                {tickets.data.length === 0 ? (
                    <EmptyState
                        icon={TicketIcon}
                        title={tab === 'active' ? 'No active tickets' : 'No resolved tickets'}
                        description={
                            tab === 'active'
                                ? 'New tickets will show up here.'
                                : 'Resolved and closed tickets will show up here.'
                        }
                        action={
                            tab === 'active' && (
                                <Button asChild variant="outline">
                                    <Link href={create().url}>New ticket</Link>
                                </Button>
                            )
                        }
                    />
                ) : (
                    <>
                        <TicketTable tickets={tickets.data} agents={agents} />
                        <Pagination meta={tickets.meta} />
                    </>
                )}
            </div>
        </>
    );
}

TicketsIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Tickets', href: index() },
    ],
};
