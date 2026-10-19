<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\Permission;
use App\Events\SlaBreached;
use App\Notifications\SlaBreached as SlaBreachedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * ARCHITECTURE.md §10: notify the assignee and all admins.
 */
class SendSlaBreachNotifications implements ShouldQueue
{
    public string $queue = 'notifications';

    public function __construct(private readonly UserRepositoryInterface $users) {}

    public function handle(SlaBreached $event): void
    {
        $recipients = collect([$event->ticket->assignedAgent])
            ->merge($this->users->activeWithPermissions(Permission::DashboardViewGlobal->value))
            ->filter()
            ->unique('id');

        Notification::send($recipients, new SlaBreachedNotification($event->ticket));
    }
}
