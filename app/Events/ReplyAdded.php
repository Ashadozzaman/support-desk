<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after commit when a PUBLIC reply is added (QUEUE_SCHEDULER_REALTIME.md
 * §4/§10). Never fired for internal notes — see InternalNoteAdded.
 */
class ReplyAdded
{
    use Dispatchable;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly TicketReply $reply,
    ) {}
}
