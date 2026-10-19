<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\ActivityRepositoryInterface;
use App\Enums\ActivityEvent;
use App\Enums\Permission;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ActivityRepository implements ActivityRepositoryInterface
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

    /**
     * Event filter → INDEX(event, created_at); otherwise INDEX(created_at)
     * (both newest-first via a backward index scan). Only the columns the
     * page shows; ticket/actor eager-loaded so it's one query each per page.
     */
    public function paginateAll(?ActivityEvent $event, ?CarbonImmutable $from, ?CarbonImmutable $to, int $perPage): LengthAwarePaginator
    {
        return $this->allQuery($event, $from, $to)->paginate($perPage)->withQueryString();
    }

    public function latestAll(?CarbonImmutable $from, ?CarbonImmutable $to, int $limit): Collection
    {
        return $this->allQuery(null, $from, $to)->limit($limit)->get();
    }

    public function countAll(?CarbonImmutable $from, ?CarbonImmutable $to): int
    {
        return $this->allQuery(null, $from, $to)->toBase()->getCountForPagination();
    }

    /**
     * @return Builder<TicketActivity>
     */
    private function allQuery(?ActivityEvent $event, ?CarbonImmutable $from, ?CarbonImmutable $to): Builder
    {
        return TicketActivity::query()
            ->select(['id', 'ticket_id', 'actor_id', 'event', 'old_values', 'new_values', 'created_at'])
            ->when($event, fn ($query, ActivityEvent $event) => $query->where('event', $event->value))
            ->when($from, fn ($query, $from) => $query->where('created_at', '>=', $from))
            ->when($to, fn ($query, $to) => $query->where('created_at', '<=', $to))
            ->with(['ticket:id,ticket_number,subject', 'actor:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
