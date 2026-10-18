<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TicketAssigned;
use App\Notifications\TicketAssigned as TicketAssignedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * ARCHITECTURE.md §10: notify the assigned agent and the customer.
 */
class SendTicketAssignedNotifications implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(TicketAssigned $event): void
    {
        $recipients = collect([$event->ticket->assignedAgent, $event->ticket->creator])
            ->filter()
            ->unique('id');

        Notification::send($recipients, new TicketAssignedNotification($event->ticket));
    }
}
