import { Head, router, usePage } from '@inertiajs/react';
import { AuditLogTable } from '@/components/audit/AuditLogTable';
import { FilterCard } from '@/components/cards/FilterCard';
import { EmptyState } from '@/components/data/EmptyState';
import { Pagination } from '@/components/data/Pagination';
import { DateRangeInput } from '@/components/form/DateRangeInput';
import { SelectInput } from '@/components/form/SelectInput';
import { Button } from '@/components/ui/button';
import { daysBefore } from '@/lib/dates';
import { dashboard } from '@/routes';
import { auditLog } from '@/routes/admin';
import type { AuditEventOption, AuditLogEntry, AuditLogFilters, AuditRetention } from '@/types/audit';
import type { Paginated } from '@/types/pagination';

const ALL = '';

export default function AuditLogIndex({
    entries,
    filters,
    events,
    today,
    retention,
}: {
    entries: Paginated<AuditLogEntry>;
    filters: AuditLogFilters;
    events: AuditEventOption[];
    today: string;
    retention: AuditRetention;
}) {
    const { errors } = usePage().props;

    // A cleared date falls back to the server default window.
    function apply(next: { log?: AuditLogFilters['log']; event?: string | null; from?: string | null; to?: string | null }) {
        const merged = { ...filters, ...next };
        // An event always belongs to one log; switching log drops a stale event.
        if (next.log !== undefined && merged.event && !merged.event.startsWith(`${next.log}.`)) {
            merged.event = null;
        }

        const query = Object.fromEntries(Object.entries(merged).filter(([, value]) => value));

        router.get(auditLog({ query }).url, {}, { preserveState: true, preserveScroll: true, replace: true });
    }

    const presets = [
        { label: 'Last 7 days', from: daysBefore(today, 6) },
        { label: 'Last 30 days', from: daysBefore(today, 29) },
        { label: 'Last 90 days', from: daysBefore(today, 89) },
    ];

    const eventOptions = events.filter((event) => !filters.log || event.log === filters.log);

    return (
        <>
            <Head title="Audit log" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-lg font-semibold">Audit log</h1>
                    <p className="text-muted-foreground text-sm">
                        Sign-ins, admin setting changes and every ticket action, newest first — last{' '}
                        {retention.default_days} days unless you pick other dates. Sign-ins are kept{' '}
                        {retention.auth_days} days, admin changes {retention.admin_days} days, ticket history for good.
                    </p>
                </div>

                <FilterCard>
                    <div className="w-full sm:w-44">
                        <SelectInput
                            name="log"
                            label="Log"
                            value={filters.log ?? ALL}
                            error={errors.log}
                            options={[
                                { value: ALL, label: 'All logs' },
                                { value: 'auth', label: 'Sign-in & account' },
                                { value: 'admin', label: 'Admin settings' },
                                { value: 'tickets', label: 'Tickets' },
                            ]}
                            onChange={(value) => apply({ log: (value || null) as AuditLogFilters['log'] })}
                        />
                    </div>
                    <div className="w-full sm:w-64">
                        <SelectInput
                            name="event"
                            label="Event"
                            value={filters.event ?? ALL}
                            error={errors.event}
                            options={[{ value: ALL, label: 'All events' }, ...eventOptions]}
                            onChange={(value) => apply({ event: value || null })}
                        />
                    </div>
                    <DateRangeInput
                        fromName="from"
                        toName="to"
                        label="Between"
                        from={filters.from}
                        to={filters.to}
                        errors={{ from: errors.from, to: errors.to }}
                        onChange={(range) => apply(range)}
                    />
                    <div className="flex flex-wrap gap-2">
                        {presets.map((preset) => (
                            <Button
                                key={preset.label}
                                type="button"
                                size="sm"
                                variant={filters.from === preset.from && filters.to === today ? 'default' : 'outline'}
                                onClick={() => apply({ from: preset.from, to: today })}
                            >
                                {preset.label}
                            </Button>
                        ))}
                    </div>
                </FilterCard>

                {entries.data.length === 0 ? (
                    <EmptyState title="No audit entries match these filters" />
                ) : (
                    <>
                        <AuditLogTable entries={entries.data} />
                        <Pagination meta={entries.meta} />
                    </>
                )}
            </div>
        </>
    );
}

AuditLogIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Audit log', href: auditLog() },
    ],
};
