<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\TicketRepositoryInterface;
use App\DTOs\CreateTicketData;
use App\DTOs\TicketFilterData;
use App\DTOs\UpdateTicketData;
use App\Enums\ActivityEvent;
use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Events\TicketCreated;
use App\Events\TicketResolved;
use App\Events\TicketStatusChanged;
use App\Exceptions\InvalidStatusTransitionException;
use App\Jobs\AutoAssignTicketJob;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TicketService
{
    public function __construct(
        private readonly TicketRepositoryInterface $tickets,
        private readonly ActivityLogService $activity,
        private readonly SlaService $sla,
    ) {}

    public function create(CreateTicketData $data, User $actor): Ticket
    {
        return DB::transaction(function () use ($data, $actor) {
            $window = $this->sla->calculate($data->priority, CarbonImmutable::now());
            $ticket = $this->tickets->create($data, $window->dueAt, $window->warningAt);

            $this->activity->log(
                $ticket,
                ActivityEvent::TicketCreated,
                $actor,
                [],
                [
                    'subject' => $ticket->subject,
                    'priority' => $ticket->priority->value,
                    'category_id' => $ticket->category_id,
                ],
            );

            AutoAssignTicketJob::dispatch($ticket->id)->afterCommit();

            DB::afterCommit(fn () => TicketCreated::dispatch($ticket));

            return $ticket;
        }, attempts: 3);
    }

    public function update(Ticket $ticket, UpdateTicketData $data, User $actor): Ticket
    {
        return DB::transaction(function () use ($ticket, $data, $actor) {
            $before = [
                'subject' => $ticket->subject,
                'description' => $ticket->description,
                'priority' => $ticket->priority->value,
                'category_id' => $ticket->category_id,
            ];

            // Recomputed from created_at (not the moment of the change) per
            // the documented assumption in ARCHITECTURE.md §8.2.
            $window = $this->sla->calculate($data->priority, CarbonImmutable::parse($ticket->created_at));
            $updated = $this->tickets->update($ticket, $data, $window->dueAt, $window->warningAt);

            if ($before['priority'] !== $data->priority->value) {
                $this->activity->log(
                    $updated,
                    ActivityEvent::PriorityChanged,
                    $actor,
                    ['priority' => $before['priority']],
                    ['priority' => $data->priority->value],
                );
            }

            if ($before['category_id'] !== $data->categoryId) {
                $this->activity->log(
                    $updated,
                    ActivityEvent::CategoryChanged,
                    $actor,
                    ['category_id' => $before['category_id']],
                    ['category_id' => $data->categoryId],
                );
            }

            $changedFields = array_filter(
                ['subject', 'description'],
                fn (string $field) => $before[$field] !== $data->{$field},
            );

            if ($changedFields !== []) {
                $this->activity->log(
                    $updated,
                    ActivityEvent::TicketUpdated,
                    $actor,
                    array_intersect_key($before, array_flip($changedFields)),
                    array_combine($changedFields, array_map(fn (string $field) => $data->{$field}, $changedFields)),
                );
            }

            return $updated;
        });
    }

    /**
     * @throws InvalidStatusTransitionException
     */
    public function changeStatus(Ticket $ticket, TicketStatus $target, User $actor): Ticket
    {
        if (! $ticket->status->canTransitionTo($target)) {
            throw new InvalidStatusTransitionException(
                "Cannot transition a ticket from '{$ticket->status->value}' to '{$target->value}'.",
            );
        }

        return DB::transaction(function () use ($ticket, $target, $actor) {
            $from = $ticket->status;
            $updated = $this->tickets->updateStatus($ticket, $target);

            $this->activity->log(
                $updated,
                ActivityEvent::StatusChanged,
                $actor,
                ['status' => $from->value],
                ['status' => $target->value],
            );

            DB::afterCommit(function () use ($updated, $from, $target) {
                TicketStatusChanged::dispatch($updated, $from, $target);

                if ($target === TicketStatus::Resolved) {
                    TicketResolved::dispatch($updated);
                }
            });

            return $updated;
        });
    }

    /**
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function paginate(User $viewer, string $tab, TicketFilterData $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->tickets->paginateFor($viewer, $tab, $filters, $perPage);
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

    /**
     * Customers for the admin "created by" filter — same precedent as
     * categoryOptions()/TicketAssignmentService::agentOptions() (direct,
     * read-only model query; no dedicated UserRepository exists for this).
     *
     * @return Collection<int, User>
     */
    public function customerOptions(): Collection
    {
        return User::role(Role::Customer->value)->orderBy('name')->get(['id', 'name']);
    }
}
