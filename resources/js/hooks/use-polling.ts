import { useConnectionStatus } from '@laravel/echo-react';
import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

const POLL_MS = 30_000;

/**
 * Fallback for when the Reverb socket is down: poll instead of silently
 * going stale (QUEUE_SCHEDULER_REALTIME.md §4 "graceful fallback"). Returns
 * whether the socket is currently connected, for a small status indicator.
 */
export function usePolling(only: string[]): boolean {
    const status = useConnectionStatus();
    const connected = status === 'connected';

    // `only` is read through a ref, not a dependency, so passing a fresh
    // array literal each render doesn't tear down and restart the interval.
    const onlyRef = useRef(only);
    onlyRef.current = only;

    useEffect(() => {
        if (connected) {
            return;
        }

        const interval = setInterval(() => router.reload({ only: onlyRef.current }), POLL_MS);

        return () => clearInterval(interval);
    }, [connected]);

    return connected;
}
