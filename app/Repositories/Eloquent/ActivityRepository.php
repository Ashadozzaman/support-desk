<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\ActivityRepositoryInterface;
use App\Enums\ActivityEvent;
use App\Enums\Permission;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class ActivityRepository implements ActivityRepositoryInterface
{
    public function create(
        Ticket $ticket,
        ActivityEvent $event,
        ?User $actor,
        array $old,
        array $new,
        array $metadata = [],
    ): TicketActivity {
        return $ticket->activities()->create([
            'actor_id' => $actor?->id,
            'event' => $event,
            'old_values' => $old,
            'new_values' => $new,
            'metadata' => $metadata,
        ]);
    }

    public function forTicket(Ticket $ticket, User $viewer): Collection
    {
        $query = $ticket->activities()->with('actor:id,name')->latest('created_at');

        if (! $viewer->can(Permission::NotesView->value)) {
            $staffOnly = array_map(fn (ActivityEvent $event) => $event->value, ActivityEvent::staffOnly());
            $query->whereNotIn('event', $staffOnly);
        }

        return $query->get();
    }
}
