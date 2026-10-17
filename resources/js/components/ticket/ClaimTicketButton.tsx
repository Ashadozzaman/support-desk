import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { claim } from '@/routes/tickets';

/**
 * Posts the claim and, if another agent won the race first, shows the
 * server's 409 message inline instead of letting Inertia's default error
 * dialog take over (ARCHITECTURE.md §7).
 */
export function ClaimTicketButton({ ticketNumber }: { ticketNumber: string }) {
    const [pending, setPending] = useState(false);
    const [error, setError] = useState<string | null>(null);

    function handleClaim() {
        setPending(true);
        setError(null);

        router.post(
            claim(ticketNumber).url,
            {},
            {
                onHttpException: (response) => {
                    const data = response.data as { message?: string };
                    setError(data?.message ?? 'This ticket was just claimed by someone else.');
                    return false;
                },
                onFinish: () => setPending(false),
            },
        );
    }

    return (
        <div className="flex flex-col items-end gap-1">
            <Button size="sm" onClick={handleClaim} disabled={pending}>
                Claim
            </Button>
            {error && <p className="text-destructive text-xs">{error}</p>}
        </div>
    );
}
