<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\ReportRepositoryInterface;
use App\DTOs\ReportFilterData;
use App\DTOs\ReportStatsData;
use App\Enums\ActivityEvent;
use App\Enums\ReportGranularity;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReportRepository implements ReportRepositoryInterface
{
    private const STATS_TABLE = 'ticket_daily_stats';

    private const SNAPSHOTS_TABLE = 'ticket_daily_snapshots';

    /**
     * Every summed measure in ticket_daily_stats.
     */
    private const MEASURES = [
        'created_count', 'resolved_count', 'sla_met_count', 'sla_breached_count', 'sum_resolution_minutes',
        'responded_count', 'sum_first_response_minutes', 'escalated_count', 'reopened_count',
    ];

    private const SUM_MEASURES_SQL = 'COALESCE(SUM(created_count), 0) AS created_count,
        COALESCE(SUM(resolved_count), 0) AS resolved_count,
        COALESCE(SUM(sla_met_count), 0) AS sla_met_count,
        COALESCE(SUM(sla_breached_count), 0) AS sla_breached_count,
        COALESCE(SUM(sum_resolution_minutes), 0) AS sum_resolution_minutes,
        COALESCE(SUM(responded_count), 0) AS responded_count,
        COALESCE(SUM(sum_first_response_minutes), 0) AS sum_first_response_minutes,
        COALESCE(SUM(escalated_count), 0) AS escalated_count,
        COALESCE(SUM(reopened_count), 0) AS reopened_count';

    public function computeDailyStats(CarbonImmutable $fromUtc, CarbonImmutable $toUtc, string $offset): array
    {
        $rows = [];

        // One query per event type, each range-scanning the index on its own
        // timestamp column. A single UNION over all five would read the same
        // rows, just harder to EXPLAIN and explain.
        $this->mergeInto($rows, $this->ticketEvents('created_at', $fromUtc, $toUtc, $offset)
            ->selectRaw('COUNT(*) AS created_count'));

        $this->mergeInto($rows, $this->ticketEvents('resolved_at', $fromUtc, $toUtc, $offset)
            ->selectRaw('COUNT(*) AS resolved_count')
            ->selectRaw('SUM(sla_met = 1) AS sla_met_count')
            ->selectRaw('SUM(sla_met = 0) AS sla_breached_count')
            ->selectRaw('COALESCE(SUM(resolution_minutes), 0) AS sum_resolution_minutes'));

        $this->mergeInto($rows, $this->ticketEvents('first_response_at', $fromUtc, $toUtc, $offset)
            ->whereNotNull('first_response_minutes')
            ->selectRaw('COUNT(*) AS responded_count')
            ->selectRaw('SUM(first_response_minutes) AS sum_first_response_minutes'));

        $this->mergeInto($rows, $this->ticketEvents('escalated_at', $fromUtc, $toUtc, $offset)
            ->selectRaw('COUNT(*) AS escalated_count'));

        $this->mergeInto($rows, $this->reopenEvents($fromUtc, $toUtc, $offset));

        return array_values($rows);
    }

    public function replaceDailyStats(string $fromDate, string $toDate, array $rows): void
    {
        DB::table(self::STATS_TABLE)->whereBetween('stat_date', [$fromDate, $toDate])->delete();

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table(self::STATS_TABLE)->insert($chunk);
        }
    }

    public function backlogNow(?ReportFilterData $filters = null): array
    {
        $query = Ticket::query()->toBase()
            ->whereIn('status', $this->activeStatuses())
            ->selectRaw('COUNT(*) AS open_count')
            ->selectRaw('COALESCE(SUM(assigned_agent_id IS NULL), 0) AS unassigned_count')
            ->selectRaw('COALESCE(SUM(sla_due_at <= ?), 0) AS breached_open_count', [now()]);

        if ($filters !== null) {
            $this->applyTicketFilters($query, $filters);
        }

        $row = $query->first();

        return [
            'open_count' => (int) ($row->open_count ?? 0),
            'unassigned_count' => (int) ($row->unassigned_count ?? 0),
            'breached_open_count' => (int) ($row->breached_open_count ?? 0),
        ];
    }

    public function saveSnapshot(string $date, array $counts): void
    {
        DB::table(self::SNAPSHOTS_TABLE)->upsert(
            [['stat_date' => $date, ...$counts, 'taken_at' => now()]],
            ['stat_date'],
            ['open_count', 'unassigned_count', 'breached_open_count', 'taken_at'],
        );
    }

    public function sumDailyStats(ReportFilterData $filters, array $groupBy): Collection
    {
        $query = DB::table(self::STATS_TABLE)
            ->whereBetween('stat_date', [$filters->from->toDateString(), $filters->to->toDateString()])
            ->selectRaw(self::SUM_MEASURES_SQL);

        $this->applyRollupFilters($query, $filters);

        // $groupBy only ever comes from ReportService literals, never input.
        foreach ($groupBy as $column) {
            if ($column === 'bucket') {
                $query->selectRaw($this->bucketExpression($filters->granularity))->groupBy('bucket')->orderBy('bucket');
            } else {
                $query->addSelect($column)->groupBy($column);
            }
        }

        return $query->get()->map(fn (object $row) => new ReportStatsData(
            createdCount: (int) $row->created_count,
            resolvedCount: (int) $row->resolved_count,
            slaMetCount: (int) $row->sla_met_count,
            slaBreachedCount: (int) $row->sla_breached_count,
            sumResolutionMinutes: (int) $row->sum_resolution_minutes,
            respondedCount: (int) $row->responded_count,
            sumFirstResponseMinutes: (int) $row->sum_first_response_minutes,
            escalatedCount: (int) $row->escalated_count,
            reopenedCount: (int) $row->reopened_count,
            bucket: isset($row->bucket) ? (string) $row->bucket : null,
            priority: isset($row->priority) ? (string) $row->priority : null,
            agentId: isset($row->agent_id) ? (int) $row->agent_id : null,
            categoryId: isset($row->category_id) ? (int) $row->category_id : null,
        ));
    }

    public function snapshots(ReportFilterData $filters): Collection
    {
        return DB::table(self::SNAPSHOTS_TABLE)
            ->whereBetween('stat_date', [$filters->from->toDateString(), $filters->to->toDateString()])
            ->orderBy('stat_date')
            ->get(['stat_date', 'open_count', 'unassigned_count', 'breached_open_count'])
            ->map(fn (object $row) => [
                'stat_date' => (string) $row->stat_date,
                'open_count' => (int) $row->open_count,
                'unassigned_count' => (int) $row->unassigned_count,
                'breached_open_count' => (int) $row->breached_open_count,
            ]);
    }

    /**
     * Frequency table (priority, minutes, count) for one duration metric;
     * ReportService turns it into medians/p90s (App\Support\Percentile).
     * Grouping by the minute value reads only the covering
     * (event_at, priority, minutes) index and returns one row per distinct
     * value, instead of sorting every matching row as a ROW_NUMBER() window
     * would.
     */
    public function durationFrequencies(ReportFilterData $filters, string $metric): Collection
    {
        [$dateColumn, $valueColumn] = match ($metric) {
            'resolution' => ['resolved_at', 'resolution_minutes'],
            'first_response' => ['first_response_at', 'first_response_minutes'],
        };

        $query = Ticket::query()->toBase()
            ->where($dateColumn, '>=', $filters->fromUtc())
            ->where($dateColumn, '<', $filters->toUtc())
            ->whereNotNull($valueColumn)
            ->select('priority', "{$valueColumn} as minutes")
            ->selectRaw('COUNT(*) AS count')
            ->groupBy('priority', $valueColumn);

        $this->applyTicketFilters($query, $filters);

        return $query->get()->map(fn (object $row) => [
            'priority' => (string) $row->priority,
            'minutes' => (int) $row->minutes,
            'count' => (int) $row->count,
        ]);
    }

    public function agingBuckets(ReportFilterData $filters): Collection
    {
        $now = now();

        $query = Ticket::query()->toBase()
            ->whereIn('status', $this->activeStatuses())
            ->select('priority')
            ->selectRaw('SUM(created_at > ?) AS age_0_1d', [$now->subDay()])
            ->selectRaw('SUM(created_at <= ? AND created_at > ?) AS age_1_3d', [$now->subDay(), $now->subDays(3)])
            ->selectRaw('SUM(created_at <= ? AND created_at > ?) AS age_3_7d', [$now->subDays(3), $now->subDays(7)])
            ->selectRaw('SUM(created_at <= ?) AS age_7d_plus', [$now->subDays(7)])
            ->selectRaw('SUM(assigned_agent_id IS NULL) AS unassigned_count')
            ->selectRaw('MIN(CASE WHEN assigned_agent_id IS NULL THEN created_at END) AS oldest_unassigned_at')
            ->groupBy('priority');

        $this->applyTicketFilters($query, $filters);

        return $query->get()->map(fn (object $row) => [
            'priority' => (string) $row->priority,
            'age_0_1d' => (int) $row->age_0_1d,
            'age_1_3d' => (int) $row->age_1_3d,
            'age_3_7d' => (int) $row->age_3_7d,
            'age_7d_plus' => (int) $row->age_7d_plus,
            'unassigned_count' => (int) $row->unassigned_count,
            'oldest_unassigned_at' => $row->oldest_unassigned_at !== null ? (string) $row->oldest_unassigned_at : null,
        ]);
    }

    public function activeCountByAgent(array $agentIds): Collection
    {
        if ($agentIds === []) {
            return collect();
        }

        return Ticket::query()->toBase()
            ->whereIn('assigned_agent_id', $agentIds)
            ->whereIn('status', $this->activeStatuses())
            ->groupBy('assigned_agent_id')
            ->selectRaw('assigned_agent_id, COUNT(*) AS active_count')
            ->pluck('active_count', 'assigned_agent_id')
            ->map(fn ($count) => (int) $count);
    }

    public function userNames(array $userIds): Collection
    {
        return User::query()->whereKey($userIds)->pluck('name', 'id');
    }

    public function categoryNames(array $categoryIds): Collection
    {
        return Category::query()->whereKey($categoryIds)->pluck('name', 'id');
    }

    /**
     * Base query for one event type: tickets whose $column falls in the
     * window, grouped by business day + the rollup dimensions (current
     * category / priority / assignee; 0 for NULL).
     */
    private function ticketEvents(string $column, CarbonImmutable $fromUtc, CarbonImmutable $toUtc, string $offset): Builder
    {
        $day = match ($column) {
            'created_at' => "DATE(CONVERT_TZ(created_at, '+00:00', ?)) AS stat_date",
            'resolved_at' => "DATE(CONVERT_TZ(resolved_at, '+00:00', ?)) AS stat_date",
            'first_response_at' => "DATE(CONVERT_TZ(first_response_at, '+00:00', ?)) AS stat_date",
            'escalated_at' => "DATE(CONVERT_TZ(escalated_at, '+00:00', ?)) AS stat_date",
            default => throw new InvalidArgumentException("Not a ticket event column: {$column}"),
        };

        return Ticket::query()->toBase()
            ->where($column, '>=', $fromUtc)
            ->where($column, '<', $toUtc)
            ->selectRaw($day, [$offset])
            ->selectRaw('COALESCE(category_id, 0) AS category_id')
            ->addSelect('priority')
            ->selectRaw('COALESCE(assigned_agent_id, 0) AS agent_id')
            ->groupBy('stat_date', 'category_id', 'priority', 'agent_id');
    }

    /**
     * Reopens are dated by when they happened (the activity row), not by
     * tickets.reopened_count, which is a running total. A customer reply
     * logs ticket.reopened; a staff Resolved → Open logs status.changed.
     */
    private function reopenEvents(CarbonImmutable $fromUtc, CarbonImmutable $toUtc, string $offset): Builder
    {
        return TicketActivity::query()->toBase()
            ->from('ticket_activities as a')
            ->join('tickets as t', 't.id', '=', 'a.ticket_id')
            ->where('a.created_at', '>=', $fromUtc)
            ->where('a.created_at', '<', $toUtc)
            ->where(function (Builder $q) {
                $q->where('a.event', ActivityEvent::TicketReopened->value)
                    ->orWhere(fn (Builder $inner) => $inner
                        ->where('a.event', ActivityEvent::StatusChanged->value)
                        ->whereRaw("a.old_values->>'$.status' = ?", [TicketStatus::Resolved->value])
                        ->whereRaw("a.new_values->>'$.status' = ?", [TicketStatus::Open->value]));
            })
            ->selectRaw("DATE(CONVERT_TZ(a.created_at, '+00:00', ?)) AS stat_date", [$offset])
            ->selectRaw('COALESCE(t.category_id, 0) AS category_id')
            ->addSelect('t.priority')
            ->selectRaw('COALESCE(t.assigned_agent_id, 0) AS agent_id')
            ->selectRaw('COUNT(*) AS reopened_count')
            ->groupBy('stat_date', 'category_id', 't.priority', 'agent_id');
    }

    /**
     * Folds one event query's grouped rows into the rollup rows, keyed by
     * the primary key, zero-filling the measures that query didn't set.
     *
     * @param  array<string, array<string, int|string>>  $rows
     */
    private function mergeInto(array &$rows, Builder $query): void
    {
        foreach ($query->get() as $row) {
            $values = (array) $row;
            $statDate = (string) $values['stat_date'];
            $categoryId = (int) $values['category_id'];
            $priority = (string) $values['priority'];
            $agentId = (int) $values['agent_id'];
            $key = "{$statDate}|{$categoryId}|{$priority}|{$agentId}";

            if (! isset($rows[$key])) {
                $rows[$key] = ['stat_date' => $statDate, 'category_id' => $categoryId, 'priority' => $priority, 'agent_id' => $agentId];
                foreach (self::MEASURES as $measure) {
                    $rows[$key][$measure] = 0;
                }
            }

            foreach (self::MEASURES as $measure) {
                if (array_key_exists($measure, $values)) {
                    $rows[$key][$measure] = (int) $rows[$key][$measure] + (int) $values[$measure];
                }
            }
        }
    }

    private function applyTicketFilters(Builder $query, ReportFilterData $filters): void
    {
        if ($filters->priority !== null) {
            $query->where('priority', $filters->priority->value);
        }
        if ($filters->categoryId !== null) {
            $query->where('category_id', $filters->categoryId);
        }
        if ($filters->agentId !== null) {
            $query->where('assigned_agent_id', $filters->agentId);
        }
    }

    private function applyRollupFilters(Builder $query, ReportFilterData $filters): void
    {
        if ($filters->priority !== null) {
            $query->where('priority', $filters->priority->value);
        }
        if ($filters->categoryId !== null) {
            $query->where('category_id', $filters->categoryId);
        }
        if ($filters->agentId !== null) {
            $query->where('agent_id', $filters->agentId);
        }
    }

    /**
     * Must match ReportGranularity::bucketStart() so zero-filled series
     * line up. DAYOFWEEK is 1 = Sunday, so weeks start on Sunday.
     *
     * @return literal-string
     */
    private function bucketExpression(ReportGranularity $granularity): string
    {
        return match ($granularity) {
            ReportGranularity::Day => 'stat_date AS bucket',
            ReportGranularity::Week => 'DATE_SUB(stat_date, INTERVAL (DAYOFWEEK(stat_date) - 1) DAY) AS bucket',
            ReportGranularity::Month => "DATE_FORMAT(stat_date, '%Y-%m-01') AS bucket",
        };
    }

    /**
     * @return list<string>
     */
    private function activeStatuses(): array
    {
        return [TicketStatus::Open->value, TicketStatus::InProgress->value, TicketStatus::WaitingForCustomer->value];
    }
}
