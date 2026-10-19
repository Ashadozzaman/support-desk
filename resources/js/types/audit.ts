export type AuditLogName = 'auth' | 'admin' | 'tickets';

export type AuditFieldChanges = Record<string, { old: Record<string, unknown>; new: Record<string, unknown> }>;

export type AuditLogEntry = {
    id: number;
    log: AuditLogName;
    event: string;
    label: string;
    causer: { id: number; name: string } | null;
    subject: { id: number; name: string } | null;
    ip: string | null;
    user_agent: string | null;
    remember: boolean | null;
    changes: AuditFieldChanges | null;
    holiday: { date: string; name: string } | null;
    /** Only on Tickets-log rows (read from ticket_activities). */
    ticket?: { number: string; subject: string };
    created_at: string;
};

export type AuditEventOption = {
    value: string;
    label: string;
    log: AuditLogName;
};

export type AuditLogFilters = {
    log: AuditLogName | null;
    event: string | null;
    from: string;
    to: string;
};

export type AuditRetention = {
    default_days: number;
    auth_days: number;
    admin_days: number;
};
