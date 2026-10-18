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
    count: number;
};

export type TicketsTrendPoint = {
    day: string;
    count: number;
};
