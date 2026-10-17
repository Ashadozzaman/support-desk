<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\TicketRepositoryInterface;
use App\DTOs\CreateTicketData;
use App\DTOs\UpdateTicketData;
use App\Enums\TicketStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class TicketService
{
    public function __construct(private readonly TicketRepositoryInterface $tickets) {}

    public function create(CreateTicketData $data): Ticket
    {
        return DB::transaction(
            // SLA is not computed yet (placeholder null; wired up in Step 9).
            fn () => $this->tickets->create($data, null, null),
            attempts: 3,
        );
    }

    public function update(Ticket $ticket, UpdateTicketData $data): Ticket
    {
        return DB::transaction(fn () => $this->tickets->update($ticket, $data));
    }

    /**
     * @throws InvalidStatusTransitionException
     */
    public function changeStatus(Ticket $ticket, TicketStatus $target): Ticket
    {
        if (! $ticket->status->canTransitionTo($target)) {
            throw new InvalidStatusTransitionException(
                "Cannot transition a ticket from '{$ticket->status->value}' to '{$target->value}'.",
            );
        }

        return DB::transaction(fn () => $this->tickets->updateStatus($ticket, $target));
    }

    /**
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function paginate(User $viewer, string $tab): LengthAwarePaginator
    {
        return $this->tickets->paginateFor($viewer, $tab);
    }

    /**
     * Active categories for the ticket create/edit form. No dedicated
     * CategoryRepository exists yet (category admin CRUD is out of scope
     * for this phase) so this is a direct, read-only model query.
     *
     * @return Collection<int, Category>
     */
    public function categoryOptions(): Collection
    {
        return Category::query()->active()->orderBy('name')->get(['id', 'name']);
    }
}
