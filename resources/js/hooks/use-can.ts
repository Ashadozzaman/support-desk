import { usePage } from '@inertiajs/react';

/**
 * Cosmetic only (ARCHITECTURE.md §3) — hides/shows UI affordances. The
 * server (policy + permission middleware) is the only real enforcement.
 */
export function useCan(permission: string): boolean {
    const { auth } = usePage().props;

    return auth.permissions.includes(permission);
}
