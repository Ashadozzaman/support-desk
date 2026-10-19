<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\Permission;
use App\Events\TicketCreated;
use App\Notifications\TicketCreated as TicketCreatedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * ARCHITECTURE.md §10: notify admins (queue visibility) and send the
 * customer a confirmation.
 */
class SendTicketCreatedNotifications implements ShouldQueue
{
    public string $queue = 'notifications';

    public function __construct(private readonly UserRepositoryInterface $users) {}

    public function handle(TicketCreated $event): void
    {
        $recipients = $this->users->activeWithPermissions(Permission::DashboardViewGlobal->value)
            ->push($event->ticket->creator)
            ->unique('id');

        NotificationFacade::send($recipients, new TicketCreatedNotification($event->ticket));
    }
}
