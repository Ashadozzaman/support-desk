import { router } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/feedback/ConfirmDialog';
import { Button } from '@/components/ui/button';
import { changeStatus } from '@/routes/tickets';
import type { TicketStatusValue } from '@/types/ticket';

const ACTION_LABELS: Record<TicketStatusValue, string> = {
    open: 'Reopen',
    in_progress: 'Mark in progress',
    waiting_for_customer: 'Wait for customer',
    resolved: 'Mark resolved',
    closed: 'Close ticket',
};

/**
 * One button per status the ticket may move to — `allowed` comes from the
 * server (TicketStatus::allowedTargets), at most two at a time, so the UI
 * never offers a transition the server would reject. Closing is final (no
 * way back out of Closed), so it asks first. A rejected change (422, e.g.
 * someone else moved the ticket meanwhile) shows the server's message inline.
 */
export function ChangeStatusButtons({
    ticketNumber,
    allowed,
}: {
    ticketNumber: string;
    allowed: TicketStatusValue[];
}) {
    const [pending, setPending] = useState(false);
    const [error, setError] = useState<string | null>(null);

    function submit(status: TicketStatusValue) {
        setPending(true);
        setError(null);

        router.patch(
            changeStatus(ticketNumber).url,
            { status },
            {
                preserveScroll: true,
                onHttpException: (response) => {
                    const data = response.data as { message?: string };
                    setError(
                        data?.message ??
                            'That status change is no longer allowed. Reload the page.',
                    );
                    return false;
                },
                onError: (errors) =>
                    setError(errors.status ?? 'Could not change the status.'),
                onFinish: () => setPending(false),
            },
        );
    }

    if (allowed.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col items-end gap-1">
            <div className="flex flex-wrap gap-2">
                {allowed.map((status) =>
                    status === 'closed' ? (
                        <ConfirmDialog
                            key={status}
                            trigger={
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={pending}
                                >
                                    {ACTION_LABELS[status]}
                                </Button>
                            }
                            title="Close this ticket?"
                            description="A closed ticket is final: it can't be reopened, not even by a customer reply."
                            confirmLabel="Close ticket"
                            onConfirm={() => submit(status)}
                        />
                    ) : (
                        <Button
                            key={status}
                            size="sm"
                            variant={
                                status === 'resolved' ? 'default' : 'outline'
                            }
                            disabled={pending}
                            onClick={() => submit(status)}
                        >
                            {ACTION_LABELS[status]}
                        </Button>
                    ),
                )}
            </div>
            {error && <p className="text-xs text-destructive">{error}</p>}
        </div>
    );
}
