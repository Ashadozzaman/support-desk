<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after commit when a PUBLIC reply is added (QUEUE_SCHEDULER_REALTIME.md
 * §4/§10). Never fired for internal notes — see InternalNoteAdded. Broadcasts
 * only to the ticket's public channel (QUEUE_SCHEDULER_REALTIME.md §4 events
 * table) — the customer may be subscribed there, so the payload must not
 * carry anything beyond the reply id.
 */
class ReplyAdded implements ShouldBroadcast
{
    use Dispatchable;

    public string $broadcastQueue = 'broadcasts';

    public function __construct(
        public readonly Ticket $ticket,
        public readonly TicketReply $reply,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tickets.{$this->ticket->ticket_number}")];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'ticket_number' => $this->ticket->ticket_number,
            'reply_id' => $this->reply->id,
        ];
    }
}
