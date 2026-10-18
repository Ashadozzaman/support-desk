import { useEcho } from '@laravel/echo-react';
import { router } from '@inertiajs/react';

/**
 * Live-updates an import's progress page (QUEUE_SCHEDULER_REALTIME.md §4).
 * `usePolling` on the page covers the case where the socket is down.
 */
export function useImportChannel(importId: number): void {
    useEcho(`imports.${importId}`, ['ImportProgressUpdated'], () =>
        router.reload({ only: ['import'] }),
    );
}
