<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Enums\ReplyType;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface ReplyRepositoryInterface
{
    public function create(Ticket $ticket, User $user, ReplyType $type, string $body): TicketReply;

    /**
     * All replies for the ticket, oldest first. Excludes internal_note
     * rows unless the viewer has the notes.view permission (CLAUDE.md
     * rule 6 — internal notes must never reach customers).
     *
     * @return Collection<int, TicketReply>
     */
    public function forTicket(Ticket $ticket, User $viewer): Collection;
}
