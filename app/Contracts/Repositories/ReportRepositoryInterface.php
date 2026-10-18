<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\DTOs\ReportFilterData;
use App\DTOs\ReportStatsData;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

interface ReportRepositoryInterface
{
    /**
     * Rollup rows for every event whose own timestamp falls in
     * [$fromUtc, $toUtc), bucketed by business day ($offset = business tz
     * UTC offset, e.g. '+06:00'). Plain non-locking SELECTs only.
     *
     * @return list<array<string, int|string>>
     */
    public function computeDailyStats(CarbonImmutable $fromUtc, CarbonImmutable $toUtc, string $offset): array;

    /**
     * Deletes every rollup row in [$fromDate, $toDate] and inserts $rows.
     * Must run inside the caller's transaction.
     *
     * @param  list<array<string, int|string>>  $rows
     */
    public function replaceDailyStats(string $fromDate, string $toDate, array $rows): void;

    /**
     * Current backlog over active tickets, optionally filtered.
     *
     * @return array{open_count: int, unassigned_count: int, breached_open_count: int}
     */
    public function backlogNow(?ReportFilterData $filters = null): array;

    /**
     * @param  array{open_count: int, unassigned_count: int, breached_open_count: int}  $counts
     */
    public function saveSnapshot(string $date, array $counts): void;

    /**
     * Summed rollup measures in the filter window, grouped by any of
     * 'bucket' (per the filter's granularity), 'priority', 'agent_id',
     * 'category_id'. An empty $groupBy returns one totals row.
     *
     * @param  list<'bucket'|'priority'|'agent_id'|'category_id'>  $groupBy
     * @return Collection<int, ReportStatsData>
     */
    public function sumDailyStats(ReportFilterData $filters, array $groupBy): Collection;

    /**
     * @return Collection<int, array{stat_date: string, open_count: int, unassigned_count: int, breached_open_count: int}>
     */
    public function snapshots(ReportFilterData $filters): Collection;

    /**
     * How many tickets took each whole number of business minutes, per
     * priority, for events in the window — the input for percentiles.
     *
     * @param  'resolution'|'first_response'  $metric
     * @return Collection<int, array{priority: string, minutes: int, count: int}>
     */
    public function durationFrequencies(ReportFilterData $filters, string $metric): Collection;

    /**
     * Active tickets per priority bucketed by age (calendar days since
     * created_at), plus unassigned count and the oldest unassigned ticket.
     *
     * @return Collection<int, array{priority: string, age_0_1d: int, age_1_3d: int, age_3_7d: int, age_7d_plus: int, unassigned_count: int, oldest_unassigned_at: ?string}>
     */
    public function agingBuckets(ReportFilterData $filters): Collection;

    /**
     * @param  list<int>  $agentIds
     * @return Collection<int, int> active ticket count keyed by agent id
     */
    public function activeCountByAgent(array $agentIds): Collection;

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, string> name keyed by id
     */
    public function userNames(array $userIds): Collection;

    /**
     * @param  list<int>  $categoryIds
     * @return Collection<int, string> name keyed by id
     */
    public function categoryNames(array $categoryIds): Collection;

    /**
     * Tickets created in the filter window (ticket export).
     */
    public function countTicketsForExport(ReportFilterData $filters): int;

    /**
     * Tickets created in the filter window with category / assignee names,
     * read in id-ordered chunks so memory stays flat however many there are.
     *
     * @return LazyCollection<int, Ticket>
     */
    public function ticketsForExport(ReportFilterData $filters): LazyCollection;
}
