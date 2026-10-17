<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TicketStatus;
use App\Jobs\AutoAssignTicketJob;
use App\Models\Ticket;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Scheduled sweep (QUEUE_SCHEDULER_REALTIME.md §3) that retries tickets no
 * agent could take at creation time — an agent may have become available
 * since, and nothing else would re-trigger the attempt.
 */
final class AutoAssignTickets extends Command
{
    protected $signature = 'tickets:auto-assign';

    protected $description = 'Re-queue auto-assignment for tickets still unassigned.';

    public function handle(): int
    {
        Ticket::query()
            ->where('status', TicketStatus::Open->value)
            ->whereNull('assigned_agent_id')
            ->chunkById(200, function (Collection $tickets): void {
                foreach ($tickets as $ticket) {
                    AutoAssignTicketJob::dispatch($ticket->id);
                }
            });

        return self::SUCCESS;
    }
}
