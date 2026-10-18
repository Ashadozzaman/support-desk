import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import type { AppNotification } from '@/types/notification';

/**
 * A notification's link. A file download (e.g. a report export) must be a
 * plain browser request — an Inertia <Link> visit expects an Inertia page.
 */
export function NotificationLink({
    notification,
    className,
    children,
}: {
    notification: AppNotification;
    className?: string;
    children: ReactNode;
}) {
    if (notification.data.download) {
        return (
            <a href={notification.data.url} className={className}>
                {children}
            </a>
        );
    }

    return (
        <Link href={notification.data.url} className={className}>
            {children}
        </Link>
    );
}
