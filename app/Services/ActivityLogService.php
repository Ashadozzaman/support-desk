<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\ActivityRepositoryInterface;
use App\Enums\ActivityEvent;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class ActivityLogService
{
    public function __construct(private readonly ActivityRepositoryInterface $activities) {}

    /**
     * Called by other services inside their own transaction (ARCHITECTURE.md
     * §15) so the activity row is atomic with the change it records.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        Ticket $ticket,
        ActivityEvent $event,
        ?User $actor,
        array $old = [],
        array $new = [],
        array $metadata = [],
    ): TicketActivity {
        return $this->activities->create($ticket, $event, $actor, $old, $new, $metadata);
    }

    /**
     * @return Collection<int, TicketActivity>
     */
    public function timelineFor(Ticket $ticket, User $viewer): Collection
    {
        return $this->activities->forTicket($ticket, $viewer);
    }
}
