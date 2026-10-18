<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after commit on every status transition (QUEUE_SCHEDULER_REALTIME.md
 * §4). Used for Step 11 broadcasting; the Resolved transition additionally
 * fires TicketResolved, which is what actually notifies the customer — a
 * notification on every intermediate transition (e.g. open -> in_progress)
 * was judged too noisy and isn't in the Step 10 notification class list.
 */
class TicketStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly TicketStatus $from,
        public readonly TicketStatus $to,
    ) {}
}
