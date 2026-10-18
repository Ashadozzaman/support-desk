<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Thin wrapper around the Notifiable::notifications() relation Laravel
 * already provides — there is no bespoke query logic here beyond what the
 * relation gives, so this stays a service rather than a repository.
 */
class NotificationService
{
    /**
     * @return LengthAwarePaginator<int, DatabaseNotification>
     */
    public function paginateFor(User $viewer): LengthAwarePaginator
    {
        return $viewer->notifications()->latest()->paginate(20)->withQueryString();
    }

    /**
     * @return Collection<int, DatabaseNotification>
     */
    public function recentFor(User $viewer, int $limit = 5): Collection
    {
        return $viewer->notifications()->latest()->limit($limit)->get();
    }

    public function unreadCountFor(User $viewer): int
    {
        return $viewer->unreadNotifications()->count();
    }

    public function markRead(User $viewer, DatabaseNotification $notification): void
    {
        abort_unless($notification->notifiable_type === $viewer::class && $notification->notifiable_id === $viewer->id, 404);

        $notification->markAsRead();
    }

    public function markAllRead(User $viewer): void
    {
        $viewer->unreadNotifications()->update(['read_at' => now()]);
    }
}
