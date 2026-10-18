<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after commit specifically when a ticket transitions to Resolved
 * (QUEUE_SCHEDULER_REALTIME.md §4/§10) — notifies the customer.
 */
class TicketResolved
{
    use Dispatchable;

    public function __construct(public readonly Ticket $ticket) {}
}
