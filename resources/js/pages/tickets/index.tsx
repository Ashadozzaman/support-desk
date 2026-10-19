import { Head, Link, router } from '@inertiajs/react';
import { PlusIcon, TicketIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/data/EmptyState';
import { Pagination } from '@/components/data/Pagination';
import { TicketTable } from '@/components/ticket/TicketTable';
import { EMPTY_TICKET_FILTERS, TicketFilters, type TicketFiltersValue } from '@/components/ticket/TicketFilters';
import { useStaffQueue } from '@/hooks/use-staff-queue';
import { dashboard } from '@/routes';
import { create, index } from '@/routes/tickets';
import type { Paginated } from '@/types/pagination';
import type { AgentOption, AssignableAgent, CategoryOption, TicketListItem } from '@/types/ticket';

type Tab = 'active' | 'resolved';

type RawFilters = Partial<Record<keyof TicketFiltersValue, string>>;

function normalizeFilters(raw: RawFilters): TicketFiltersValue {
    return {
        ...EMPTY_TICKET_FILTERS,
        ticket_number: raw.ticket_number ?? null,
        keyword: raw.keyword ?? null,
        status: (raw.status as TicketFiltersValue['status']) ?? null,
        priority: (raw.priority as TicketFiltersValue['priority']) ?? null,
        category_id: raw.category_id ? Number(raw.category_id) : null,
        assigned_agent_id: raw.assigned_agent_id ? Number(raw.assigned_agent_id) : null,
        created_by: raw.created_by ? Number(raw.created_by) : null,
        created_from: raw.created_from ?? null,
        created_to: raw.created_to ?? null,
        sla_state: (raw.sla_state as TicketFiltersValue['sla_state']) ?? null,
    };
}

export default function TicketsIndex({
    tickets,
    tab,
    filters,
    categories,
    agents,
    assignableAgents,
    customers,
}: {
    tickets: Paginated<TicketListItem>;
    tab: Tab;
    filters: RawFilters;
    categories: CategoryOption[];
    agents: AgentOption[];
    assignableAgents: { data: AssignableAgent[] };
    customers: AgentOption[];
}) {
    useStaffQueue(['tickets']);

    const currentFilters = normalizeFilters(filters);

    const tabs: { value: Tab; label: string }[] = [
        { value: 'active', label: 'Active' },
        { value: 'resolved', label: 'Resolved' },
    ];

    function applyFilters(next: TicketFiltersValue) {
        router.get(
            index({ query: { ...next, tab } }).url,
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

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
                            href={index({ query: { ...currentFilters, tab: item.value } }).url}
                            preserveState
                            preserveScroll
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

                <TicketFilters
                    filters={currentFilters}
                    categories={categories}
                    agents={agents}
                    customers={customers}
                    onChange={applyFilters}
                />

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
                        <TicketTable tickets={tickets.data} agents={assignableAgents.data} />
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
