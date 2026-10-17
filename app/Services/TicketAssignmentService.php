<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Assignment\AssignmentStrategy;
use App\Contracts\Repositories\TicketRepositoryInterface;
use App\Enums\ActivityEvent;
use App\Enums\Role;
use App\Events\TicketAssigned;
use App\Exceptions\TicketAlreadyAssignedException;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class TicketAssignmentService
{
    public function __construct(
        private readonly TicketRepositoryInterface $tickets,
        private readonly ActivityLogService $activity,
        private readonly AssignmentStrategy $strategy,
    ) {}

    /**
     * Agent self-service claim (ARCHITECTURE.md §7). The atomic conditional
     * UPDATE is the only race guard needed — no read-then-write.
     *
     * @throws TicketAlreadyAssignedException
     */
    public function claim(Ticket $ticket, User $agent): Ticket
    {
        return DB::transaction(function () use ($ticket, $agent) {
            if (! $this->tickets->claimIfUnassigned($ticket, $agent->id)) {
                throw new TicketAlreadyAssignedException(
                    'This ticket has already been claimed by another agent.',
                );
            }

            $claimed = $ticket->refresh();

            $this->activity->log(
                $claimed,
                ActivityEvent::TicketAssigned,
                $agent,
                ['assigned_agent_id' => null],
                ['assigned_agent_id' => $agent->id],
            );

            DB::afterCommit(fn () => TicketAssigned::dispatch($claimed));

            return $claimed;
        }, attempts: 3);
    }

    /**
     * Admin manual assign/reassign (ARCHITECTURE.md §6.1). agentId is
     * validated by AssignTicketRequest (active, has an agent profile) —
     * same precedent as TicketService::categoryOptions() for the lookup.
     */
    public function assign(Ticket $ticket, int $agentId, User $actor): Ticket
    {
        return DB::transaction(function () use ($ticket, $agentId, $actor) {
            $agent = User::findOrFail($agentId);

            $event = $ticket->assigned_agent_id === null
                ? ActivityEvent::TicketAssigned
                : ActivityEvent::TicketReassigned;
            $previousAgentId = $ticket->assigned_agent_id;

            $updated = $this->tickets->assign($ticket, $agent->id);

            $this->activity->log(
                $updated,
                $event,
                $actor,
                ['assigned_agent_id' => $previousAgentId],
                ['assigned_agent_id' => $agent->id],
            );

            DB::afterCommit(fn () => TicketAssigned::dispatch($updated));

            return $updated;
        });
    }

    /**
     * Automatic assignment (ARCHITECTURE.md §6.2), called from
     * AutoAssignTicketJob. Re-locks the ticket row first so two auto-assign
     * attempts for the same ticket can never both succeed; if the strategy
     * finds no available agent the ticket is left unassigned for the
     * `tickets:auto-assign` sweep to retry.
     */
    public function autoAssign(Ticket $ticket): Ticket
    {
        return DB::transaction(function () use ($ticket) {
            $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->first();

            if ($locked === null || $locked->assigned_agent_id !== null) {
                return $locked ?? $ticket;
            }

            $agent = $this->strategy->pick($locked->category_id);

            if ($agent === null) {
                return $locked;
            }

            $updated = $this->tickets->assign($locked, $agent->id);

            $this->activity->log(
                $updated,
                ActivityEvent::TicketAssigned,
                null,
                ['assigned_agent_id' => null],
                ['assigned_agent_id' => $agent->id],
                ['auto' => true],
            );

            DB::afterCommit(fn () => TicketAssigned::dispatch($updated));

            return $updated;
        });
    }

    /**
     * Active agents for the AssignAgentDialog dropdown. Direct, read-only
     * model query — same precedent as TicketService::categoryOptions()
     * (no dedicated UserRepository exists for this phase).
     *
     * @return Collection<int, User>
     */
    public function agentOptions(): Collection
    {
        return User::role(Role::Agent->value)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
