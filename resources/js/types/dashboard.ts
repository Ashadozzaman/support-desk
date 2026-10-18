import type { TicketPriorityValue, UserSummary } from '@/types/ticket';

export type DashboardScope = 'global' | 'agent' | 'customer';

export type DashboardStats = {
    open_total: number;
    unassigned: number | null;
    critical: number | null;
    sla_breached: number;
    sla_approaching: number;
    resolved: number;
};

export type PriorityBreakdown = {
    priority: TicketPriorityValue;
    count: number;
};

export type AgentBreakdown = {
    agent: UserSummary | null;
    active_count: number;
    open_count: number;
    in_progress_count: number;
    waiting_count: number;
    stuck_count: number;
    resolved_7d_count: number;
    resolved_30d_count: number;
    max_active_tickets: number | null;
    is_available: boolean | null;
};

export type TicketsTrendPoint = {
    day: string;
    count: number;
};
