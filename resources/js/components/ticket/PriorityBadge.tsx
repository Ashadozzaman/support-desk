import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { TICKET_PRIORITY_LABELS, type TicketPriorityValue } from '@/types/ticket';

const PRIORITY_STYLES: Record<TicketPriorityValue, string> = {
    low: 'border-transparent bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    medium: 'border-transparent bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
    high: 'border-transparent bg-orange-100 text-orange-800 dark:bg-orange-950 dark:text-orange-300',
    critical: 'border-transparent bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
};

export function PriorityBadge({ priority }: { priority: TicketPriorityValue }) {
    return (
        <Badge className={cn(PRIORITY_STYLES[priority])}>
            {TICKET_PRIORITY_LABELS[priority]}
        </Badge>
    );
}
