import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { IMPORT_STATUS_LABELS, type ImportStatusValue } from '@/types/import';

const STATUS_STYLES: Record<ImportStatusValue, string> = {
    pending: 'border-transparent bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
    processing: 'border-transparent bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    completed:
        'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    completed_with_errors:
        'border-transparent bg-orange-100 text-orange-800 dark:bg-orange-950 dark:text-orange-300',
    failed: 'border-transparent bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
};

export function ImportStatusBadge({ status }: { status: ImportStatusValue }) {
    return <Badge className={cn(STATUS_STYLES[status])}>{IMPORT_STATUS_LABELS[status]}</Badge>;
}
