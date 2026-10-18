<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after commit when an internal note is added. Listeners must only
 * notify staff (CLAUDE.md rule 6) — never the customer who created the
 * ticket. Broadcasts ONLY on the `.staff` channel (QUEUE_SCHEDULER_REALTIME.md
 * §4) — a customer can never authorise that channel (routes/channels.php),
 * so this can never reach them even if a future change reused this event
 * elsewhere.
 */
class InternalNoteAdded implements ShouldBroadcast
{
    use Dispatchable;

    public string $broadcastQueue = 'broadcasts';

    public function __construct(
        public readonly Ticket $ticket,
        public readonly TicketReply $note,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tickets.{$this->ticket->ticket_number}.staff")];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'ticket_number' => $this->ticket->ticket_number,
            'note_id' => $this->note->id,
        ];
    }
}
