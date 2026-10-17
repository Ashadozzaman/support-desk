<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Enums\ActivityEvent;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface ActivityRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $metadata
     */
    public function create(
        Ticket $ticket,
        ActivityEvent $event,
        ?User $actor,
        array $old,
        array $new,
        array $metadata = [],
    ): TicketActivity;

    /**
     * Activities for the ticket, newest first. Excludes staff-only events
     * (ARCHITECTURE.md §15) unless the viewer has the notes.view permission.
     *
     * @return Collection<int, TicketActivity>
     */
    public function forTicket(Ticket $ticket, User $viewer): Collection;
}
