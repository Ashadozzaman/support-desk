<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProcessSlaBreachJob;
use App\Jobs\ProcessSlaWarningJob;
use App\Models\Ticket;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Scheduled every minute (QUEUE_SCHEDULER_REALTIME.md §3). Two passes over
 * active tickets, each using its own index (ARCHITECTURE.md §9):
 * (status, sla_warning_at) for approaching, (status, sla_due_at) for
 * breached. The idempotency flags in the jobs are what actually prevent
 * duplicate notifications — this command only decides who to check.
 */
class SlaScan extends Command
{
    protected $signature = 'sla:scan';

    protected $description = 'Dispatch SLA warning/breach jobs for tickets approaching or past their deadline.';

    public function handle(): int
    {
        Ticket::query()
            ->active()
            ->whereNotNull('sla_warning_at')
            ->whereNull('sla_warning_notified_at')
            ->where('sla_warning_at', '<=', now())
            ->chunkById(200, function (Collection $tickets): void {
                foreach ($tickets as $ticket) {
                    ProcessSlaWarningJob::dispatch($ticket->id);
                }
            });

        Ticket::query()
            ->active()
            ->whereNotNull('sla_due_at')
            ->whereNull('sla_breach_notified_at')
            ->where('sla_due_at', '<=', now())
            ->chunkById(200, function (Collection $tickets): void {
                foreach ($tickets as $ticket) {
                    ProcessSlaBreachJob::dispatch($ticket->id);
                }
            });

        return self::SUCCESS;
    }
}
