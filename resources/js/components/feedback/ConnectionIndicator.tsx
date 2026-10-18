import { usePolling } from '@/hooks/use-polling';

/**
 * Small dot next to the bell: green while the Reverb socket is connected,
 * amber while `usePolling` is covering for a dropped connection
 * (QUEUE_SCHEDULER_REALTIME.md §4 "graceful fallback").
 */
export function ConnectionIndicator() {
    const connected = usePolling(['notifications']);

    return (
        <span
            title={connected ? 'Live updates connected' : 'Reconnecting… polling for updates'}
            className={`size-2 rounded-full ${connected ? 'bg-emerald-500' : 'bg-amber-500'}`}
        />
    );
}
