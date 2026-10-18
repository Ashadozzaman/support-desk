import { useEcho } from '@laravel/echo-react';
import { router } from '@inertiajs/react';
import { useRef } from 'react';

const DEBOUNCE_MS = 500;

/**
 * Keeps the staff queue / dashboard fresh: a new ticket, a claim, or a
 * status change reloads only the given props (QUEUE_SCHEDULER_REALTIME.md
 * §4). Debounced so a burst of events (e.g. an import) doesn't trigger a
 * reload per ticket.
 */
export function useStaffQueue(only: string[] = ['tickets', 'stats']): void {
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEcho('staff.queue', ['TicketCreated', 'TicketAssigned', 'TicketStatusChanged'], () => {
        if (timer.current) {
            clearTimeout(timer.current);
        }

        timer.current = setTimeout(() => router.reload({ only }), DEBOUNCE_MS);
    });
}
