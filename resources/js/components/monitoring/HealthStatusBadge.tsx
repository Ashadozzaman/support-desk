import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { HealthStatusValue } from '@/types/monitoring';

const STYLES: Record<HealthStatusValue, string> = {
    ok: 'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    warning: 'border-transparent bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    failed: 'border-transparent bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    crashed: 'border-transparent bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    skipped: 'border-transparent bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
};

const LABELS: Record<HealthStatusValue, string> = {
    ok: 'OK',
    warning: 'Warning',
    failed: 'Failed',
    crashed: 'Crashed',
    skipped: 'Skipped',
};

export function HealthStatusBadge({ status }: { status: HealthStatusValue }) {
    return <Badge className={cn(STYLES[status])}>{LABELS[status]}</Badge>;
}
