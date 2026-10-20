import { DataTable } from '@/components/data/DataTable';
import { Badge } from '@/components/ui/badge';
import type { MailboxSync, MailboxSyncStatus } from '@/types/mailbox';

const STATUS_VARIANT: Record<MailboxSyncStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    running: 'outline',
    completed: 'default',
    baseline: 'secondary',
    failed: 'destructive',
};

export function MailboxSyncTable({ syncs }: { syncs: MailboxSync[] }) {
    return (
        <DataTable
            rows={syncs}
            rowKey={(sync) => sync.id}
            columns={[
                {
                    header: 'Started',
                    cell: (sync) => (
                        <div className="space-y-0.5">
                            <p className="font-medium">{new Date(sync.started_at).toLocaleString()}</p>
                            <p className="text-muted-foreground text-xs">{sync.triggered_by ?? 'Scheduler'}</p>
                        </div>
                    ),
                },
                {
                    header: 'Status',
                    cell: (sync) => (
                        <div className="space-y-1">
                            <Badge variant={STATUS_VARIANT[sync.status]}>{sync.status_label}</Badge>
                            {sync.error && <p className="text-destructive text-xs">{sync.error}</p>}
                        </div>
                    ),
                },
                { header: 'Fetched', cell: (sync) => sync.fetched_count, numeric: true },
                { header: 'New tickets', cell: (sync) => sync.created_count, numeric: true },
                { header: 'Replies', cell: (sync) => sync.replied_count, numeric: true },
                { header: 'Skipped', cell: (sync) => sync.skipped_count, numeric: true },
                { header: 'Duplicates', cell: (sync) => sync.duplicate_count, numeric: true },
                { header: 'Failed', cell: (sync) => sync.failed_count, numeric: true },
            ]}
        />
    );
}
