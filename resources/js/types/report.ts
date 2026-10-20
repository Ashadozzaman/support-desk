import type {
    AgentOption,
    CategoryOption,
    TicketPriorityValue,
} from '@/types/ticket';

export type ReportTab =
    | 'overview'
    | 'sla'
    | 'response'
    | 'agents'
    | 'aging'
    | 'categories'
    | 'ai';

export type ReportGranularity = 'day' | 'week' | 'month';

/** Effective (server-defaulted) filters; dates are `YYYY-MM-DD` in the business timezone. */
export type ReportFilters = {
    from: string;
    to: string;
    granularity: ReportGranularity;
    priority: TicketPriorityValue | null;
    category_id: number | null;
    agent_id: number | null;
};

export type ReportOptions = {
    categories: CategoryOption[];
    agents: AgentOption[];
};

export type OverviewReport = {
    totals: {
        created: number;
        resolved: number;
        escalated: number;
        reopened: number;
    };
    /** `bucket` is the first day of the day/week/month, `YYYY-MM-DD`. */
    series: { bucket: string; created: number; resolved: number }[];
    backlog: {
        stat_date: string;
        open_count: number;
        unassigned_count: number;
        breached_open_count: number;
    }[];
    now: {
        open_count: number;
        unassigned_count: number;
        breached_open_count: number;
    };
};

export type Compliance = {
    met: number;
    breached: number;
    /** null when nothing with an SLA was resolved. */
    compliance_pct: number | null;
};

export type SlaReport = {
    totals: Compliance & { escalated: number };
    byPriority: (Compliance & { priority: TicketPriorityValue })[];
    series: (Compliance & { bucket: string })[];
};

/** Business minutes. */
export type DurationStats = {
    avg: number | null;
    median: number | null;
    p90: number | null;
    count: number;
};

export type DurationBreakdown = Record<
    'all' | TicketPriorityValue,
    DurationStats
>;

export type ResponseResolutionReport = {
    percentileWindow: { from: string; to: string };
    firstResponse: DurationBreakdown;
    resolution: DurationBreakdown;
};

export type AgentReportRow = {
    agent_id: number;
    name: string | null;
    resolved: number;
    sla_met_pct: number | null;
    avg_resolution_minutes: number | null;
    avg_first_response_minutes: number | null;
    reopen_rate_pct: number | null;
    escalated: number;
    active_now: number;
};

export type AgingBuckets = {
    age_0_1d: number;
    age_1_3d: number;
    age_3_7d: number;
    age_7d_plus: number;
    unassigned_count: number;
};

export type AgingReport = {
    byPriority: (AgingBuckets & {
        priority: TicketPriorityValue;
        oldest_unassigned_at: string | null;
    })[];
    totals: AgingBuckets;
};

export type CategoryReportRow = {
    /** 0 = no category. */
    category_id: number;
    name: string | null;
    created: number;
    share_pct: number | null;
    resolved: number;
    avg_resolution_minutes: number | null;
    breach_rate_pct: number | null;
};

export type AiReport = {
    chats: {
        total: number;
        handed_off: number;
        closed: number;
        active: number;
        /** Closed without a ticket ÷ all conversations; null when there were none. */
        deflection_pct: number | null;
    };
    calls: {
        total: number;
        succeeded: number;
        refused: number;
        failed: number;
        tokens: number;
        avg_latency_ms: number | null;
    };
};

export type ReportData = {
    overview: OverviewReport;
    sla: SlaReport;
    response: ResponseResolutionReport;
    agents: AgentReportRow[];
    aging: AgingReport;
    categories: CategoryReportRow[];
    ai: AiReport;
};
