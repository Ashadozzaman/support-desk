<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ReportAggregationService;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;

/**
 * Scheduled (routes/console.php): today every 15 minutes, yesterday once
 * after midnight so late changes to yesterday's tickets settle. Run by hand
 * with --from/--to to (re)build history, e.g. after reports:backfill.
 * Dates are business-timezone calendar days. Idempotent — the range is
 * recomputed, not added to.
 */
class ReportsAggregate extends Command
{
    protected $signature = 'reports:aggregate
        {--from= : First day, Y-m-d (default: today)}
        {--to= : Last day, Y-m-d (default: --from)}
        {--yesterday : Recompute yesterday only}';

    protected $description = 'Recompute the ticket_daily_stats report rollup for a date range.';

    public function handle(ReportAggregationService $aggregation): int
    {
        $timezone = config('support.sla.timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();

        try {
            $from = $this->option('yesterday')
                ? $today->subDay()
                : $this->day($this->option('from'), $timezone) ?? $today;
            $to = $this->option('yesterday')
                ? $from
                : $this->day($this->option('to'), $timezone) ?? $from;
        } catch (InvalidFormatException) {
            $this->error('Dates must be Y-m-d.');

            return self::INVALID;
        }

        if ($to->lt($from)) {
            $this->error('--to must not be before --from.');

            return self::INVALID;
        }

        $rows = $aggregation->recompute($from, $to);

        $this->info("Recomputed {$from->toDateString()} → {$to->toDateString()}: {$rows} rollup rows.");

        return self::SUCCESS;
    }

    private function day(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone)
            ?: throw new InvalidFormatException("Invalid date: {$value}");
    }
}
