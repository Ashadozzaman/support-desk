<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Ticket;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after commit whenever a ticket gains or changes its assigned agent
 * (claim, manual assign/reassign, auto-assign). Broadcasts so the staff
 * queue removes the ticket, the ticket's own page updates its assignee
 * badge, and the newly assigned agent gets an instant bell notification.
 */
class TicketAssigned implements ShouldBroadcast
{
    use Dispatchable;

    public string $broadcastQueue = 'broadcasts';

    public function __construct(public readonly Ticket $ticket) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('staff.queue'),
            new PrivateChannel("tickets.{$this->ticket->ticket_number}"),
        ];

        if ($this->ticket->assigned_agent_id !== null) {
            $channels[] = new PrivateChannel("App.Models.User.{$this->ticket->assigned_agent_id}");
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'ticket_number' => $this->ticket->ticket_number,
            'assigned_agent_id' => $this->ticket->assigned_agent_id,
        ];
    }
}
