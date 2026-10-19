import { Link } from '@inertiajs/react';
import { DataTable } from '@/components/data/DataTable';
import { Badge } from '@/components/ui/badge';
import { show as showTicket } from '@/routes/tickets';
import type { AuditFieldChanges, AuditLogEntry } from '@/types/audit';

function formatValue(value: unknown): string {
    if (value === null || value === undefined) {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'yes' : 'no';
    }

    return String(value);
}

/** "critical: resolution_minutes 120 → 180" — one line per changed field. */
function changeLines(changes: AuditFieldChanges): string[] {
    return Object.entries(changes).flatMap(([key, change]) =>
        Object.keys(change.new).map(
            (field) => `${key}: ${field} ${formatValue(change.old[field])} → ${formatValue(change.new[field])}`,
        ),
    );
}

function details(entry: AuditLogEntry): string[] {
    if (entry.changes) {
        return changeLines(entry.changes);
    }

    if (entry.holiday) {
        return [`${entry.holiday.date} — ${entry.holiday.name}`];
    }

    if (entry.subject && entry.subject.id !== entry.causer?.id) {
        return [`Account: ${entry.subject.name}`];
    }

    return entry.remember ? ['Remember me'] : [];
}

/**
 * Admin audit log (Step 19c). All text is rendered as plain text — user
 * agents and holiday names are user-controlled.
 */
export function AuditLogTable({ entries }: { entries: AuditLogEntry[] }) {
    return (
        <DataTable
            rows={entries}
            rowKey={(entry) => entry.id}
            columns={[
                {
                    header: 'Event',
                    cell: (entry) => (
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge variant={entry.event === 'auth.login_failed' || entry.event === 'auth.lockout' ? 'destructive' : 'secondary'}>
                                {entry.log}
                            </Badge>
                            <span>{entry.label}</span>
                        </div>
                    ),
                },
                { header: 'When', cell: (entry) => new Date(entry.created_at).toLocaleString() },
                { header: 'By', cell: (entry) => entry.causer?.name ?? 'Anonymous' },
                {
                    header: 'Details',
                    cell: (entry) => (
                        <div className="flex flex-col gap-0.5 text-xs font-normal">
                            {entry.ticket && (
                                <Link href={showTicket(entry.ticket.number).url} className="hover:underline">
                                    {entry.ticket.number} — {entry.ticket.subject}
                                </Link>
                            )}
                            {details(entry).map((line) => (
                                <span key={line}>{line}</span>
                            ))}
                        </div>
                    ),
                },
                {
                    header: 'IP',
                    cell: (entry) => (
                        <span className="text-xs font-normal" title={entry.user_agent ?? undefined}>
                            {entry.ip ?? '—'}
                        </span>
                    ),
                },
            ]}
        />
    );
}
