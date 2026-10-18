import { Head, router } from '@inertiajs/react';
import { BellOff } from 'lucide-react';
import { EmptyState } from '@/components/data/EmptyState';
import { NotificationLink } from '@/components/feedback/NotificationLink';
import { Pagination } from '@/components/data/Pagination';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { index, read, readAll } from '@/routes/notifications';
import type { Paginated } from '@/types/pagination';
import type { AppNotification } from '@/types/notification';

export default function NotificationsIndex({
    items,
}: {
    items: Paginated<AppNotification>;
}) {
    function markAllRead() {
        router.post(readAll().url, {}, { preserveScroll: true });
    }

    function markOneRead(id: string) {
        router.post(read(id).url, {}, { preserveScroll: true });
    }

    return (
        <>
            <Head title="Notifications" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-semibold">Notifications</h1>
                    <Button variant="outline" size="sm" onClick={markAllRead}>
                        Mark all read
                    </Button>
                </div>

                {items.data.length === 0 ? (
                    <EmptyState
                        icon={BellOff}
                        title="No notifications"
                        description="You're all caught up."
                    />
                ) : (
                    <>
                        <div className="divide-y rounded-lg border">
                            {items.data.map((notification) => (
                                <div
                                    key={notification.id}
                                    className="flex items-center justify-between gap-4 px-4 py-3"
                                >
                                    <NotificationLink
                                        notification={notification}
                                        className={`text-sm ${notification.read_at === null ? 'font-medium' : 'text-muted-foreground'}`}
                                    >
                                        {notification.data.message}
                                        <span className="block text-xs text-muted-foreground">
                                            {new Date(
                                                notification.created_at,
                                            ).toLocaleString()}
                                        </span>
                                    </NotificationLink>
                                    {notification.read_at === null && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                markOneRead(notification.id)
                                            }
                                        >
                                            Mark read
                                        </Button>
                                    )}
                                </div>
                            ))}
                        </div>
                        <Pagination meta={items.meta} />
                    </>
                )}
            </div>
        </>
    );
}

NotificationsIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Notifications', href: index() },
    ],
};
