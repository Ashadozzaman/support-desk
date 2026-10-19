<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Assignment\AssignmentStrategy;
use App\Contracts\Repositories\AgentRepositoryInterface;
use App\Contracts\Repositories\TicketRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\ActivityEvent;
use App\Events\TicketAssigned;
use App\Exceptions\TicketAlreadyAssignedException;
use App\Jobs\AutoAssignTicketJob;
use App\Models\AgentProfile;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TicketAssignmentService
{
    public function __construct(
        private readonly TicketRepositoryInterface $tickets,
        private readonly ActivityLogService $activity,
        private readonly AssignmentStrategy $strategy,
        private readonly AgentRepositoryInterface $agents,
        private readonly UserRepositoryInterface $users,
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
                // Expected outcome of the race, not an error — info level.
                Log::info('ticket.claim_conflict', ['ticket_id' => $ticket->id, 'agent_id' => $agent->id]);

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
     * validated by AssignTicketRequest (active, agent profile, tickets.claim) —
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
                $this->logNoCandidate($locked);

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
     * Scheduled sweep for tickets no agent could take at creation time.
     * Queues at most as many tickets as there are free agent slots: queuing
     * the whole backlog every minute when every agent is full produced a job
     * per unassigned ticket per minute (~60k), none of which could succeed,
     * and starved every queue behind `default`. Read-only, so no transaction;
     * each job re-checks and locks in autoAssign().
     *
     * @return int number of jobs queued
     */
    public function queueUnassignedForRetry(): int
    {
        $agents = $this->agents->withFreeCapacity();

        $freeSlots = (int) $agents->sum(
            fn (AgentProfile $profile) => $profile->max_active_tickets - (int) $profile->active_count,
        );

        if ($freeSlots === 0) {
            return 0;
        }

        // An agent with no category skills takes any category (same rule as
        // candidatesFor), so one of those removes the category filter.
        $categoryIds = $agents->contains(fn (AgentProfile $profile) => $profile->user->categories->isEmpty())
            ? null
            : $agents->flatMap(fn (AgentProfile $profile) => $profile->user->categories->modelKeys())->unique()->values()->all();

        // Tickets under a minute old still have their own create-time job.
        $ticketIds = $this->tickets->unassignedIdsForSweep(now()->subMinute()->toImmutable(), $categoryIds, $freeSlots);

        $ticketIds->each(fn (int $id) => AutoAssignTicketJob::dispatch($id));

        return $ticketIds->count();
    }

    /**
     * The sweep retries every unassigned ticket each minute, so logging every
     * miss would write one line per ticket per minute. What an operator needs
     * is "category X has no agent", so it's logged once per category per 10
     * minutes (Cache::add is atomic: only the first miss in the window logs).
     */
    private function logNoCandidate(Ticket $ticket): void
    {
        $key = 'log:auto-assign-no-candidate:'.($ticket->category_id ?? 'none');

        if (Cache::add($key, true, now()->addMinutes(10))) {
            Log::notice('assignment.no_candidate', ['ticket_id' => $ticket->id, 'category_id' => $ticket->category_id]);
        }
    }

    /**
     * Agents for the list's agent filter: active, with an agent profile
     * and `tickets.claim` — the same rule auto-assign uses.
     *
     * @return Collection<int, User>
     */
    public function agentOptions(): Collection
    {
        return $this->users->assignableAgents();
    }

    /**
     * Agents for the AssignAgentDialog, with their current active-ticket
     * count and capacity so the admin can spread the load.
     *
     * @return Collection<int, User>
     */
    public function assignOptions(): Collection
    {
        return $this->users->assignableAgentsWithWorkload();
    }
}
