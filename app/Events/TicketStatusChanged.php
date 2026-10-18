<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after commit on every status transition (QUEUE_SCHEDULER_REALTIME.md
 * §4). The Resolved transition additionally fires TicketResolved, which is
 * what actually notifies the customer — a notification on every
 * intermediate transition (e.g. open -> in_progress) was judged too noisy
 * and isn't in the Step 10 notification class list. Broadcasting every
 * transition is still useful: it's what keeps the SLA badge and the staff
 * queue's status column live.
 */
class TicketStatusChanged implements ShouldBroadcast
{
    use Dispatchable;

    public string $broadcastQueue = 'broadcasts';

    public function __construct(
        public readonly Ticket $ticket,
        public readonly TicketStatus $from,
        public readonly TicketStatus $to,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("tickets.{$this->ticket->ticket_number}"),
            new PrivateChannel('staff.queue'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'ticket_number' => $this->ticket->ticket_number,
            'status' => $this->to->value,
        ];
    }
}
