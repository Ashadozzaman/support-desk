import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { TICKET_STATUS_LABELS, type TicketStatusValue } from '@/types/ticket';

const STATUS_STYLES: Record<TicketStatusValue, string> = {
    open: 'border-transparent bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300',
    in_progress:
        'border-transparent bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    waiting_for_customer:
        'border-transparent bg-purple-100 text-purple-800 dark:bg-purple-950 dark:text-purple-300',
    resolved:
        'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    closed: 'border-transparent bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
};

export function StatusBadge({ status }: { status: TicketStatusValue }) {
    return (
        <Badge className={cn(STATUS_STYLES[status])}>{TICKET_STATUS_LABELS[status]}</Badge>
    );
}
