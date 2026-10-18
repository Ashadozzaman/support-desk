<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\ReportRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds the report rollups (ENHANCEMENTS.md §1.2). Recomputed, never
 * incremented: running it twice for the same range gives the same rows.
 *
 * Deliberately NOT `INSERT … SELECT … ON DUPLICATE KEY UPDATE`:
 *  - under REPEATABLE READ, INSERT … SELECT takes shared locks on every
 *    `tickets` row it reads, so a 15-minute run would stall ticket updates;
 *    a plain SELECT is a non-locking consistent read;
 *  - ON DUPLICATE KEY UPDATE only touches groups that still exist — a
 *    ticket that changed category would leave its old group's row stale.
 *    DELETE the range + INSERT, in one transaction, has no such gap.
 */
class ReportAggregationService
{
    /**
     * Days recomputed per transaction, so a full-history rebuild never
     * holds one huge delete/insert open.
     */
    private const DAYS_PER_BATCH = 31;

    public function __construct(private readonly ReportRepositoryInterface $reports) {}

    /**
     * $from/$to are inclusive business-timezone calendar days.
     *
     * @return int rollup rows written
     */
    public function recompute(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $written = 0;

        for ($start = $from->startOfDay(); $start->lte($to); $start = $start->addDays(self::DAYS_PER_BATCH)) {
            $end = $start->addDays(self::DAYS_PER_BATCH - 1)->min($to->startOfDay());

            $rows = $this->reports->computeDailyStats(
                $start->utc(),
                $end->addDay()->utc(),
                // Fixed offset so CONVERT_TZ works without MySQL's tz tables;
                // exact for a no-DST zone like Asia/Dhaka (same approach as
                // AgentRepository's per-day counts).
                $start->format('P'),
            );

            DB::transaction(fn () => $this->reports->replaceDailyStats($start->toDateString(), $end->toDateString(), $rows));

            $written += count($rows);
        }

        return $written;
    }

    /**
     * End-of-day backlog for $date (ticket status isn't stored per day, so
     * the backlog trend only exists from the day snapshots started).
     */
    public function snapshot(CarbonImmutable $date): void
    {
        $counts = $this->reports->backlogNow();

        DB::transaction(fn () => $this->reports->saveSnapshot($date->toDateString(), $counts));
    }
}
