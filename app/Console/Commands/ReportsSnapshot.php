<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ReportAggregationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduled nightly just before midnight (business tz): records that day's
 * end-of-day backlog. Status isn't stored historically, so this is the only
 * source for the backlog trend — it can't be rebuilt for past days.
 * Re-running on the same day overwrites that day's row.
 */
class ReportsSnapshot extends Command
{
    protected $signature = 'reports:snapshot';

    protected $description = 'Record today\'s open / unassigned / breached backlog in ticket_daily_snapshots.';

    public function handle(ReportAggregationService $aggregation): int
    {
        $today = CarbonImmutable::now(config('support.sla.timezone'))->startOfDay();

        $aggregation->snapshot($today);

        $this->info("Snapshot saved for {$today->toDateString()}.");

        return self::SUCCESS;
    }
}
