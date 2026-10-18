import { Link, router, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useNotifications } from '@/hooks/use-notifications';
import { index, read, readAll } from '@/routes/notifications';
import type { AppNotification } from '@/types/notification';

export function NotificationBell() {
    const { notifications } = usePage().props;

    useNotifications();

    if (!notifications) {
        return null;
    }

    const { unreadCount, recent } = notifications;

    function markAllRead() {
        router.post(readAll().url, {}, { preserveScroll: true });
    }

    function openNotification(notification: AppNotification) {
        if (notification.read_at === null) {
            router.post(
                read(notification.id).url,
                {},
                { preserveScroll: true, onFinish: () => router.visit(notification.data.url) },
            );

            return;
        }

        router.visit(notification.data.url);
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="relative">
                    <Bell className="size-5" />
                    {unreadCount > 0 && (
                        <Badge
                            variant="destructive"
                            className="absolute -top-1 -right-1 h-4 min-w-4 justify-center rounded-full px-1 text-[10px]"
                        >
                            {unreadCount > 9 ? '9+' : unreadCount}
                        </Badge>
                    )}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-80">
                <DropdownMenuLabel className="flex items-center justify-between">
                    <span>Notifications</span>
                    {unreadCount > 0 && (
                        <button type="button" onClick={markAllRead} className="text-muted-foreground text-xs font-normal hover:underline">
                            Mark all read
                        </button>
                    )}
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                {recent.length === 0 && <p className="text-muted-foreground px-2 py-4 text-center text-sm">No notifications yet.</p>}
                {recent.map((notification) => (
                    <DropdownMenuItem
                        key={notification.id}
                        onClick={() => openNotification(notification)}
                        className={notification.read_at === null ? 'font-medium' : 'text-muted-foreground'}
                    >
                        {notification.data.message}
                    </DropdownMenuItem>
                ))}
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                    <Link href={index().url}>View all notifications</Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
