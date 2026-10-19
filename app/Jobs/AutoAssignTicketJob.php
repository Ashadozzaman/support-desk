<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Ticket;
use App\Services\TicketAssignmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatched after commit from TicketService::create() (QUEUE_SCHEDULER_REALTIME.md §1,
 * queue `default`). Unique per ticket so a burst of retries from the
 * `tickets:auto-assign` sweep never double-queues the same ticket.
 */
class AutoAssignTicketJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60];

    public int $timeout = 30;

    /**
     * Without this the unique lock lives until the job finishes — and if the
     * job row is deleted (`queue:clear`) the lock is orphaned for the cache
     * driver's 24h default, silently blocking that ticket from the sweep.
     */
    public int $uniqueFor = 600;

    public function __construct(public readonly int $ticketId) {}

    public function uniqueId(): string
    {
        return (string) $this->ticketId;
    }

    public function handle(TicketAssignmentService $assignments): void
    {
        $ticket = Ticket::find($this->ticketId);

        if ($ticket === null || $ticket->assigned_agent_id !== null) {
            return;
        }

        $assignments->autoAssign($ticket);
    }

    /**
     * Generic failure log + admin alert: AlertOnFailedJob. This line adds the
     * job's own ids, which the generic queue log doesn't know.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('assignment.auto_assign_failed', ['ticket_id' => $this->ticketId, 'exception' => $exception::class]);
    }
}
