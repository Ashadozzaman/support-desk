import type { UserSummary } from '@/types/ticket';

export type AgentPerformanceSummary = {
    agent: UserSummary;
    active_count: number;
    open_count: number;
    in_progress_count: number;
    waiting_count: number;
    stuck_count: number;
    /** Resolved/closed with resolved_at inside the selected date range. */
    completed_count: number;
    max_active_tickets: number;
    is_available: boolean;
};

/** Effective (server-defaulted) range, as `YYYY-MM-DD` business-timezone dates. */
export type AgentPerformanceFilters = {
    from: string;
    to: string;
};

export type AgentDailyRow = {
    /** `YYYY-MM-DD`, business timezone. */
    date: string;
    assigned: number;
    completed: number;
};

export type AgentDailyStats = {
    /** `YYYY-MM` */
    month: string;
    days: AgentDailyRow[];
    totals: { assigned: number; completed: number };
};

export type AgentStatusBreakdown = {
    open: number;
    in_progress: number;
    waiting_for_customer: number;
    resolved: number;
    closed: number;
};
