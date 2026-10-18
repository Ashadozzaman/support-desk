<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after commit when an internal note is added. Listeners must only
 * notify staff (CLAUDE.md rule 6) — never the customer who created the
 * ticket.
 */
class InternalNoteAdded
{
    use Dispatchable;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly TicketReply $note,
    ) {}
}
