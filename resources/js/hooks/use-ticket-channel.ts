import { useEcho } from '@laravel/echo-react';
import { router } from '@inertiajs/react';

/**
 * Live-updates a ticket's show page (QUEUE_SCHEDULER_REALTIME.md §4). Both
 * channels are subscribed unconditionally — a customer's browser is simply
 * refused by `routes/channels.php` on the `.staff` channel (internal notes
 * and SLA/escalation data never reach them), so there is nothing to branch
 * on here.
 */
export function useTicketChannel(ticketNumber: string): void {
    useEcho(
        `tickets.${ticketNumber}`,
        ['ReplyAdded', 'TicketStatusChanged', 'TicketAssigned'],
        () => router.reload({ only: ['ticket', 'replies', 'activities'] }),
    );

    useEcho(
        `tickets.${ticketNumber}.staff`,
        ['InternalNoteAdded', 'SlaWarningTriggered', 'SlaBreached'],
        () => router.reload({ only: ['ticket', 'replies', 'activities'] }),
    );
}
