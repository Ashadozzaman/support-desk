<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TicketAssignmentService;
use Illuminate\Console\Command;

/**
 * Scheduled sweep (QUEUE_SCHEDULER_REALTIME.md §3) that retries tickets no
 * agent could take at creation time — an agent may have become available
 * since, and nothing else would re-trigger the attempt.
 */
class AutoAssignTickets extends Command
{
    protected $signature = 'tickets:auto-assign';

    protected $description = 'Re-queue auto-assignment for unassigned tickets, up to the agents\' free capacity.';

    public function handle(TicketAssignmentService $assignments): int
    {
        $this->info($assignments->queueUnassignedForRetry().' ticket(s) queued for auto-assignment.');

        return self::SUCCESS;
    }
}
