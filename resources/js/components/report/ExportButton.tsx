import { DownloadIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useCan } from '@/hooks/use-can';
import { summary, tickets } from '@/routes/reports/export';
import type { ReportFilters, ReportTab } from '@/types/report';

/**
 * Plain <a> links, not Inertia <Link>: the response is a file the browser
 * should save. A ticket export too large to stream comes back as a redirect
 * with a "queued" toast instead, and the file arrives via a notification.
 * Hidden without reports.export (cosmetic — the routes enforce it).
 */
export function ExportButton({
    tab,
    filters,
}: {
    tab: ReportTab;
    filters: ReportFilters;
}) {
    const canExport = useCan('reports.export');

    if (!canExport) {
        return null;
    }

    return (
        <div className="flex flex-wrap gap-2">
            <Button asChild size="sm" variant="outline">
                <a href={summary({ query: { ...filters, tab } }).url}>
                    <DownloadIcon />
                    Export this report
                </a>
            </Button>
            <Button asChild size="sm" variant="outline">
                <a href={tickets({ query: { ...filters } }).url}>
                    <DownloadIcon />
                    Export tickets
                </a>
            </Button>
        </div>
    );
}
