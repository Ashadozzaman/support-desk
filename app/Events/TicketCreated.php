<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after commit when a ticket is created (QUEUE_SCHEDULER_REALTIME.md
 * §4/§10). Notified in Step 10; broadcasting is added in Step 11.
 */
class TicketCreated
{
    use Dispatchable;

    public function __construct(public readonly Ticket $ticket) {}
}
