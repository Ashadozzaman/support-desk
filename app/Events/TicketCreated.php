<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Ticket;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after commit when a ticket is created (QUEUE_SCHEDULER_REALTIME.md
 * §4/§10). Broadcasts to the staff queue only — a brand-new ticket has no
 * assignee yet, so there is no ticket-specific channel subscriber waiting
 * for it.
 */
class TicketCreated implements ShouldBroadcast
{
    use Dispatchable;

    public string $broadcastQueue = 'broadcasts';

    public function __construct(public readonly Ticket $ticket) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('staff.queue')];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'ticket_number' => $this->ticket->ticket_number,
            'priority' => $this->ticket->priority->value,
        ];
    }
}
