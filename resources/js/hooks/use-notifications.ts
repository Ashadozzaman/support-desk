import { useEchoNotification } from '@laravel/echo-react';
import { router, usePage } from '@inertiajs/react';
import { toast } from 'sonner';

/**
 * Subscribes to the user's private notification channel
 * (`App.Models.User.{id}`, routes/channels.php) so the bell updates the
 * instant a queued notification lands, instead of waiting for the next
 * full Inertia page load (QUEUE_SCHEDULER_REALTIME.md §4).
 */
export function useNotifications(): void {
    const { auth } = usePage().props;
    const userId = auth.user?.id;

    // Channel auth rejects this for a guest (routes/channels.php) — Echo
    // just never subscribes, so a harmless placeholder is fine here.
    useEchoNotification<{ message: string; url: string }>(
        `App.Models.User.${userId ?? 'guest'}`,
        (notification) => {
            toast(notification.message);
            router.reload({ only: ['notifications'] });
        },
    );
}
