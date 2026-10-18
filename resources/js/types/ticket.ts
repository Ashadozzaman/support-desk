export type TicketStatusValue =
    | 'open'
    | 'in_progress'
    | 'waiting_for_customer'
    | 'resolved'
    | 'closed';

export type TicketPriorityValue = 'low' | 'medium' | 'high' | 'critical';

export const TICKET_STATUS_LABELS: Record<TicketStatusValue, string> = {
    open: 'Open',
    in_progress: 'In progress',
    waiting_for_customer: 'Waiting for customer',
    resolved: 'Resolved',
    closed: 'Closed',
};

export const TICKET_PRIORITY_LABELS: Record<TicketPriorityValue, string> = {
    low: 'Low',
    medium: 'Medium',
    high: 'High',
    critical: 'Critical',
};

export type SlaStateValue =
    | 'within'
    | 'approaching'
    | 'breached'
    | 'met'
    | 'met_late';

export const SLA_STATE_LABELS: Record<SlaStateValue, string> = {
    within: 'Within SLA',
    approaching: 'Approaching SLA',
    breached: 'SLA breached',
    met: 'SLA met',
    met_late: 'SLA met late',
};

const ACTIVE_STATUSES: TicketStatusValue[] = [
    'open',
    'in_progress',
    'waiting_for_customer',
];

export function getSlaState(ticket: {
    status: TicketStatusValue;
    sla_due_at: string | null;
    sla_warning_at: string | null;
    sla_met: boolean | null;
}): SlaStateValue | null {
    if (ticket.sla_due_at === null) {
        return null;
    }

    if (!ACTIVE_STATUSES.includes(ticket.status)) {
        return ticket.sla_met ? 'met' : 'met_late';
    }

    const now = Date.now();

    if (new Date(ticket.sla_due_at).getTime() <= now) {
        return 'breached';
    }

    if (
        ticket.sla_warning_at !== null &&
        new Date(ticket.sla_warning_at).getTime() <= now
    ) {
        return 'approaching';
    }

    return 'within';
}

export type CategoryOption = {
    id: number;
    name: string;
};

export type UserSummary = {
    id: number;
    name: string;
};

export type TicketListAbilities = {
    view: boolean;
    update: boolean;
    change_status: boolean;
    claim: boolean;
    assign: boolean;
};

export type TicketAbilities = Omit<TicketListAbilities, 'view'>;

export type TicketListItem = {
    ticket_number: string;
    subject: string;
    status: TicketStatusValue;
    priority: TicketPriorityValue;
    category: CategoryOption | null;
    assigned_agent: UserSummary | null;
    creator: UserSummary;
    sla_due_at: string | null;
    sla_warning_at: string | null;
    sla_met: boolean | null;
    created_at: string;
    can: TicketListAbilities;
};

export type Ticket = {
    ticket_number: string;
    subject: string;
    description: string;
    status: TicketStatusValue;
    priority: TicketPriorityValue;
    category: CategoryOption | null;
    assigned_agent: UserSummary | null;
    creator: UserSummary;
    sla_due_at: string | null;
    sla_warning_at: string | null;
    escalated_at: string | null;
    escalation_level: number;
    first_response_at: string | null;
    last_reply_at: string | null;
    resolved_at: string | null;
    sla_met: boolean | null;
    created_at: string;
    updated_at: string;
    /** Statuses this ticket may move to; empty unless the viewer may change status. */
    allowed_statuses: TicketStatusValue[];
    can: TicketAbilities;
};

export type AgentOption = {
    id: number;
    name: string;
};

export type ActivityItem = {
    id: number;
    event: string;
    description: string;
    actor: UserSummary | null;
    created_at: string;
};
