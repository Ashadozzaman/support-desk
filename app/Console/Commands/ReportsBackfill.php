<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TicketMetricsService;
use Illuminate\Console\Command;

/**
 * One-off (not scheduled): fills first_response_minutes, resolution_minutes,
 * sla_met and reopened_count for tickets created before those columns were
 * written at event time, or bulk-inserted by PerformanceSeeder. Safe to
 * re-run — only NULL metrics are filled and reopen counts are SET from the
 * activity trail, never incremented.
 */
class ReportsBackfill extends Command
{
    protected $signature = 'reports:backfill {--chunk=1000 : Tickets per transaction}';

    protected $description = 'Backfill report metrics (response/resolution minutes, sla_met, reopen count) on existing tickets.';

    public function handle(TicketMetricsService $metrics): int
    {
        $result = $metrics->backfill(
            max(1, (int) $this->option('chunk')),
            fn (int $filled) => $this->output->write("\r  {$filled} tickets filled"),
        );

        $this->newLine();
        $this->info("Filled metrics on {$result['metrics']} tickets; updated reopen counts on {$result['reopens']}.");

        return self::SUCCESS;
    }
}
