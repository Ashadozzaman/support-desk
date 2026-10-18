import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { getSlaState, SLA_STATE_LABELS, type SlaStateValue, type TicketStatusValue } from '@/types/ticket';

const SLA_STYLES: Record<SlaStateValue, string> = {
    within: 'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    approaching: 'border-transparent bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    breached: 'border-transparent bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    met: 'border-transparent bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    met_late: 'border-transparent bg-orange-100 text-orange-800 dark:bg-orange-950 dark:text-orange-300',
};

type SlaBadgeTicket = {
    status: TicketStatusValue;
    sla_due_at: string | null;
    sla_warning_at: string | null;
    sla_met: boolean | null;
};

export function SlaBadge({ ticket }: { ticket: SlaBadgeTicket }) {
    const state = getSlaState(ticket);

    if (state === null) {
        return null;
    }

    return <Badge className={cn(SLA_STYLES[state])}>{SLA_STATE_LABELS[state]}</Badge>;
}
