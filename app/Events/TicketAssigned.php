<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after commit whenever a ticket gains or changes its assigned agent
 * (claim, manual assign/reassign, auto-assign). No listeners yet — wired up
 * for notifications in Step 10 and broadcasting in Step 11.
 */
final class TicketAssigned
{
    use Dispatchable;

    public function __construct(public readonly Ticket $ticket) {}
}
