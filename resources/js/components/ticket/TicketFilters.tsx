import { Button } from '@/components/ui/button';
import { FilterCard } from '@/components/cards/FilterCard';
import { DateRangeInput } from '@/components/form/DateRangeInput';
import { SearchInput } from '@/components/form/SearchInput';
import { SelectInput } from '@/components/form/SelectInput';
import {
    SLA_STATE_LABELS,
    TICKET_PRIORITY_LABELS,
    TICKET_STATUS_LABELS,
    type AgentOption,
    type CategoryOption,
    type SlaStateValue,
    type TicketPriorityValue,
    type TicketStatusValue,
} from '@/types/ticket';

const ANY = '__any__';

export type TicketFiltersValue = {
    ticket_number: string | null;
    keyword: string | null;
    status: TicketStatusValue | null;
    priority: TicketPriorityValue | null;
    category_id: number | null;
    assigned_agent_id: number | null;
    created_by: number | null;
    created_from: string | null;
    created_to: string | null;
    sla_state: SlaStateValue | null;
};

export const EMPTY_TICKET_FILTERS: TicketFiltersValue = {
    ticket_number: null,
    keyword: null,
    status: null,
    priority: null,
    category_id: null,
    assigned_agent_id: null,
    created_by: null,
    created_from: null,
    created_to: null,
    sla_state: null,
};

function hasActiveFilter(filters: TicketFiltersValue): boolean {
    return Object.values(filters).some((value) => value !== null);
}

export function TicketFilters({
    filters,
    categories,
    agents,
    customers,
    onChange,
}: {
    filters: TicketFiltersValue;
    categories: CategoryOption[];
    agents: AgentOption[];
    customers: AgentOption[];
    onChange: (next: TicketFiltersValue) => void;
}) {
    function set<K extends keyof TicketFiltersValue>(key: K, value: TicketFiltersValue[K]) {
        onChange({ ...filters, [key]: value });
    }

    return (
        <FilterCard>
            <SearchInput
                key={`ticket-number-${filters.ticket_number ?? ''}`}
                value={filters.ticket_number ?? ''}
                onSearch={(value) => set('ticket_number', value || null)}
                placeholder="Ticket number…"
                className="w-40"
            />
            <SearchInput
                key={`keyword-${filters.keyword ?? ''}`}
                value={filters.keyword ?? ''}
                onSearch={(value) => set('keyword', value || null)}
                placeholder="Search subject/description…"
                className="w-64"
            />
            <SelectInput
                name="status"
                label="Status"
                value={filters.status ?? ANY}
                options={[
                    { value: ANY, label: 'Any status' },
                    ...Object.entries(TICKET_STATUS_LABELS).map(([value, label]) => ({ value, label })),
                ]}
                onChange={(value) => set('status', value === ANY ? null : (value as TicketStatusValue))}
            />
            <SelectInput
                name="priority"
                label="Priority"
                value={filters.priority ?? ANY}
                options={[
                    { value: ANY, label: 'Any priority' },
                    ...Object.entries(TICKET_PRIORITY_LABELS).map(([value, label]) => ({ value, label })),
                ]}
                onChange={(value) => set('priority', value === ANY ? null : (value as TicketPriorityValue))}
            />
            <SelectInput
                name="category_id"
                label="Category"
                value={filters.category_id === null ? ANY : String(filters.category_id)}
                options={[
                    { value: ANY, label: 'Any category' },
                    ...categories.map((category) => ({ value: String(category.id), label: category.name })),
                ]}
                onChange={(value) => set('category_id', value === ANY ? null : Number(value))}
            />
            {agents.length > 0 && (
                <SelectInput
                    name="assigned_agent_id"
                    label="Agent"
                    value={filters.assigned_agent_id === null ? ANY : String(filters.assigned_agent_id)}
                    options={[
                        { value: ANY, label: 'Any agent' },
                        ...agents.map((agent) => ({ value: String(agent.id), label: agent.name })),
                    ]}
                    onChange={(value) => set('assigned_agent_id', value === ANY ? null : Number(value))}
                />
            )}
            {customers.length > 0 && (
                <SelectInput
                    name="created_by"
                    label="Customer"
                    value={filters.created_by === null ? ANY : String(filters.created_by)}
                    options={[
                        { value: ANY, label: 'Any customer' },
                        ...customers.map((customer) => ({ value: String(customer.id), label: customer.name })),
                    ]}
                    onChange={(value) => set('created_by', value === ANY ? null : Number(value))}
                />
            )}
            <SelectInput
                name="sla_state"
                label="SLA"
                value={filters.sla_state ?? ANY}
                options={[
                    { value: ANY, label: 'Any SLA state' },
                    ...Object.entries(SLA_STATE_LABELS).map(([value, label]) => ({ value, label })),
                ]}
                onChange={(value) => set('sla_state', value === ANY ? null : (value as SlaStateValue))}
            />
            <DateRangeInput
                from={filters.created_from}
                to={filters.created_to}
                onChange={({ from, to }) => onChange({ ...filters, created_from: from, created_to: to })}
            />
            {hasActiveFilter(filters) && (
                <Button type="button" variant="ghost" size="sm" onClick={() => onChange(EMPTY_TICKET_FILTERS)}>
                    Clear filters
                </Button>
            )}
        </FilterCard>
    );
}
