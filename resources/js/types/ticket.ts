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
    can: TicketAbilities;
};

export type ActivityItem = {
    id: number;
    event: string;
    description: string;
    actor: UserSummary | null;
    created_at: string;
};
