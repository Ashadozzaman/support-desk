import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { useCan } from '@/hooks/use-can';
import { index } from '@/routes/reports';
import type { ReportFilters, ReportTab } from '@/types/report';

const TABS: { tab: ReportTab; label: string }[] = [
    { tab: 'overview', label: 'Overview' },
    { tab: 'sla', label: 'SLA compliance' },
    { tab: 'response', label: 'Response & resolution' },
    { tab: 'agents', label: 'Agents' },
    { tab: 'aging', label: 'Aging' },
    { tab: 'categories', label: 'Categories' },
];

/**
 * Tabs are links, not client state: each tab is its own request, so the
 * server only computes the report being looked at. Filters carry over.
 * Agents (reports.view-own) only see their own Agents row — hiding the other
 * tabs is cosmetic; ReportPolicy::viewTab enforces it.
 */
export function ReportTabs({
    active,
    filters,
}: {
    active: ReportTab;
    filters: ReportFilters;
}) {
    const canViewAll = useCan('reports.view');
    const tabs = canViewAll ? TABS : TABS.filter((t) => t.tab === 'agents');

    return (
        <nav className="flex flex-wrap gap-2" aria-label="Report">
            {tabs.map(({ tab, label }) => (
                <Button
                    key={tab}
                    asChild
                    size="sm"
                    variant={tab === active ? 'default' : 'outline'}
                >
                    <Link
                        href={index({ query: { ...filters, tab } }).url}
                        preserveScroll
                        aria-current={tab === active ? 'page' : undefined}
                    >
                        {label}
                    </Link>
                </Button>
            ))}
        </nav>
    );
}
