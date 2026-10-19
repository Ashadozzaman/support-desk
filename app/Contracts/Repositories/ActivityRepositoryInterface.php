<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Enums\ActivityEvent;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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

    /**
     * Every ticket's activity, newest first, for the admin audit page
     * (Tickets log). Admin-only, so staff-only events are included.
     *
     * @return LengthAwarePaginator<int, TicketActivity>
     */
    public function paginateAll(?ActivityEvent $event, ?CarbonImmutable $from, ?CarbonImmutable $to, int $perPage): LengthAwarePaginator;

    /**
     * The newest $limit activities (all tickets) — one side of the audit
     * page's merged "All logs" view.
     *
     * @return Collection<int, TicketActivity>
     */
    public function latestAll(?CarbonImmutable $from, ?CarbonImmutable $to, int $limit): Collection;

    public function countAll(?CarbonImmutable $from, ?CarbonImmutable $to): int;
}
