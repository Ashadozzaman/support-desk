<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TicketResolved;
use App\Notifications\TicketResolved as TicketResolvedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * ARCHITECTURE.md §10: notify the customer.
 */
class SendTicketResolvedNotifications implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(TicketResolved $event): void
    {
        $event->ticket->creator->notify(new TicketResolvedNotification($event->ticket));
    }
}
