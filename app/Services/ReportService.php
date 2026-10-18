<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\ReportRepositoryInterface;
use App\DTOs\ReportFilterData;
use App\DTOs\ReportStatsData;
use App\Enums\Permission;
use App\Enums\TicketPriority;
use App\Models\User;
use App\Support\Percentile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Report read side (ENHANCEMENTS.md §1.1). Averages and counts come from
 * the ticket_daily_stats rollup (small, instant); medians/p90s and the
 * "right now" numbers are live queries over `tickets`. Returns plain
 * arrays — shaping for charts is the Resources' job in Step 18.
 *
 * Averages divide summed minutes by the count of events, which assumes
 * every resolved/responded ticket has its minutes filled — true for new
 * tickets, and for old ones after `reports:backfill`.
 */
class ReportService
{
    /**
     * Percentiles are live over `tickets`, so their window is capped; the
     * rollup-backed averages/counts use the full (≤ 366-day) range. 30, not
     * the 90 first planned: at ~1M tickets the 90-day frequency table took
     * ~3s per metric, 30 days ~0.45s (DATABASE.md §7).
     */
    public const PERCENTILE_MAX_DAYS = 30;

    private const PERCENTILE_CACHE_SECONDS = 300;

    /**
     * Aging is a "right now" view; a minute of staleness is fine and keeps
     * its ~1s scan over the active tickets off most requests (same TTL idea
     * as the dashboard).
     */
    private const AGING_CACHE_SECONDS = 60;

    public function __construct(private readonly ReportRepositoryInterface $reports) {}

    /**
     * Created vs resolved per bucket, the backlog trend (nightly
     * snapshots), and the live backlog.
     *
     * @return array<string, mixed>
     */
    public function overview(ReportFilterData $filters): array
    {
        $totals = $this->totals($filters);

        return [
            'totals' => [
                'created' => $totals->createdCount,
                'resolved' => $totals->resolvedCount,
                'escalated' => $totals->escalatedCount,
                'reopened' => $totals->reopenedCount,
            ],
            'series' => $this->series($filters, fn (ReportStatsData $row) => [
                'created' => $row->createdCount,
                'resolved' => $row->resolvedCount,
            ]),
            'backlog' => $this->reports->snapshots($filters)->values()->all(),
            'now' => $this->reports->backlogNow($filters),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function slaCompliance(ReportFilterData $filters): array
    {
        $totals = $this->totals($filters);
        $byPriority = $this->reports->sumDailyStats($filters, ['priority'])->keyBy('priority');

        return [
            'totals' => [...$this->compliance($totals), 'escalated' => $totals->escalatedCount],
            'byPriority' => array_map(
                fn (TicketPriority $priority) => [
                    'priority' => $priority->value,
                    ...$this->compliance($byPriority->get($priority->value) ?? new ReportStatsData),
                ],
                TicketPriority::cases(),
            ),
            'series' => $this->series($filters, fn (ReportStatsData $row) => $this->compliance($row)),
        ];
    }

    /**
     * Average (rollup) plus median / p90 (live, cached 5 min) of first
     * response and resolution time, in business minutes, overall and per
     * priority.
     *
     * @return array<string, mixed>
     */
    public function responseResolution(ReportFilterData $filters): array
    {
        $window = $filters->between($filters->to->subDays(self::PERCENTILE_MAX_DAYS - 1)->max($filters->from), $filters->to);
        $totals = $this->totals($filters);
        $byPriority = $this->reports->sumDailyStats($filters, ['priority'])->keyBy('priority');

        return [
            'percentileWindow' => ['from' => $window->from->toDateString(), 'to' => $window->to->toDateString()],
            'firstResponse' => $this->durationStats($window, 'first_response', $totals, $byPriority,
                fn (ReportStatsData $row) => $row->avgFirstResponseMinutes()),
            'resolution' => $this->durationStats($window, 'resolution', $totals, $byPriority,
                fn (ReportStatsData $row) => $row->avgResolutionMinutes()),
        ];
    }

    /**
     * Per-agent performance. Scoping, not just hiding (ARCHITECTURE.md §3
     * point 3): reports.view sees every agent; reports.view-own is forced to
     * the viewer's own row whatever agent filter was asked for; anyone else
     * gets nothing.
     *
     * @return list<array<string, mixed>>
     */
    public function agentPerformance(User $viewer, ReportFilterData $filters): array
    {
        if (! $viewer->can(Permission::ReportsView->value)) {
            if (! $viewer->can(Permission::ReportsViewOwn->value)) {
                return [];
            }

            $filters = $filters->forAgent($viewer->id);
        }

        $rows = $this->reports->sumDailyStats($filters, ['agent_id'])
            ->filter(fn (ReportStatsData $row) => $row->agentId !== 0)
            ->values();

        $agentIds = array_values($rows->map(fn (ReportStatsData $row) => (int) $row->agentId)->all());
        $names = $this->reports->userNames($agentIds);
        $active = $this->reports->activeCountByAgent($agentIds);

        return array_values($rows
            ->map(fn (ReportStatsData $row) => [
                'agent_id' => (int) $row->agentId,
                'name' => $names->get((int) $row->agentId),
                'resolved' => $row->resolvedCount,
                'sla_met_pct' => $row->compliancePercent(),
                'avg_resolution_minutes' => $row->avgResolutionMinutes(),
                'avg_first_response_minutes' => $row->avgFirstResponseMinutes(),
                'reopen_rate_pct' => ReportStatsData::percent($row->reopenedCount, $row->resolvedCount),
                'escalated' => $row->escalatedCount,
                'active_now' => $active->get((int) $row->agentId, 0),
            ])
            ->sortBy('name')
            ->all());
    }

    /**
     * Live: active tickets by age, per priority. Ages are calendar days —
     * "how long has the customer been waiting", not business time.
     *
     * @return array<string, mixed>
     */
    public function aging(ReportFilterData $filters): array
    {
        $rows = collect(Cache::remember(
            "reports.aging.{$filters->cacheKey()}",
            self::AGING_CACHE_SECONDS,
            fn () => $this->reports->agingBuckets($filters)->all(),
        ))->keyBy('priority');
        $buckets = ['age_0_1d', 'age_1_3d', 'age_3_7d', 'age_7d_plus', 'unassigned_count'];

        $byPriority = array_map(function (TicketPriority $priority) use ($rows, $buckets) {
            $row = $rows->get($priority->value);
            $counts = [];
            foreach ($buckets as $bucket) {
                $counts[$bucket] = $row[$bucket] ?? 0;
            }

            return [
                'priority' => $priority->value,
                ...$counts,
                'oldest_unassigned_at' => $row['oldest_unassigned_at'] ?? null,
            ];
        }, TicketPriority::cases());

        $totals = [];
        foreach ($buckets as $bucket) {
            $totals[$bucket] = array_sum(array_column($byPriority, $bucket));
        }

        return ['byPriority' => $byPriority, 'totals' => $totals];
    }

    /**
     * Volume, share of volume, average resolution and breach rate per
     * category (0 = no category).
     *
     * @return list<array<string, mixed>>
     */
    public function categories(ReportFilterData $filters): array
    {
        $rows = $this->reports->sumDailyStats($filters, ['category_id']);
        $total = $rows->sum(fn (ReportStatsData $row) => $row->createdCount);
        $names = $this->reports->categoryNames(
            array_values(array_filter($rows->map(fn (ReportStatsData $row) => (int) $row->categoryId)->all())),
        );

        return array_values($rows
            ->map(fn (ReportStatsData $row) => [
                'category_id' => (int) $row->categoryId,
                'name' => $row->categoryId === 0 ? null : $names->get((int) $row->categoryId),
                'created' => $row->createdCount,
                'share_pct' => ReportStatsData::percent($row->createdCount, $total),
                'resolved' => $row->resolvedCount,
                'avg_resolution_minutes' => $row->avgResolutionMinutes(),
                'breach_rate_pct' => ReportStatsData::percent($row->slaBreachedCount, $row->slaMetCount + $row->slaBreachedCount),
            ])
            ->sortByDesc('created')
            ->all());
    }

    private function totals(ReportFilterData $filters): ReportStatsData
    {
        return $this->reports->sumDailyStats($filters, [])->first() ?? new ReportStatsData;
    }

    /**
     * @param  'resolution'|'first_response'  $metric
     * @param  Collection<array-key, ReportStatsData>  $byPriority
     * @param  callable(ReportStatsData): ?int  $average
     * @return array<string, array{avg: ?int, median: ?int, p90: ?int, count: int}>
     */
    private function durationStats(ReportFilterData $window, string $metric, ReportStatsData $totals, Collection $byPriority, callable $average): array
    {
        $percentiles = Cache::remember(
            "reports.percentiles.{$metric}.{$window->cacheKey()}",
            self::PERCENTILE_CACHE_SECONDS,
            fn () => $this->percentilesFrom($this->reports->durationFrequencies($window, $metric)),
        );
        $none = ['median' => null, 'p90' => null, 'count' => 0];

        $result = ['all' => ['avg' => $average($totals), ...($percentiles['all'] ?? $none)]];

        foreach (TicketPriority::cases() as $priority) {
            $result[$priority->value] = [
                'avg' => $average($byPriority->get($priority->value) ?? new ReportStatsData),
                ...($percentiles[$priority->value] ?? $none),
            ];
        }

        return $result;
    }

    /**
     * Median/p90 per priority and overall ('all'), from one frequency table.
     *
     * @param  Collection<int, array{priority: string, minutes: int, count: int}>  $frequencies
     * @return array<string, array{median: ?int, p90: ?int, count: int}>
     */
    private function percentilesFrom(Collection $frequencies): array
    {
        $tables = ['all' => []];

        foreach ($frequencies as $row) {
            $tables[$row['priority']][$row['minutes']] = $row['count'];
            $tables['all'][$row['minutes']] = ($tables['all'][$row['minutes']] ?? 0) + $row['count'];
        }

        return array_map(fn (array $table) => [
            'median' => Percentile::nearestRank($table, 0.5),
            'p90' => Percentile::nearestRank($table, 0.9),
            'count' => array_sum($table),
        ], $tables);
    }

    /**
     * One entry per bucket from `from` to `to`, zero-filled, so a chart
     * never skips a quiet day/week/month.
     *
     * @param  callable(ReportStatsData): array<string, mixed>  $values
     * @return list<array<string, mixed>>
     */
    private function series(ReportFilterData $filters, callable $values): array
    {
        $byBucket = $this->reports->sumDailyStats($filters, ['bucket'])->keyBy('bucket');
        $granularity = $filters->granularity;
        $series = [];

        for ($bucket = $granularity->bucketStart($filters->from); $bucket->lte($filters->to); $bucket = $granularity->next($bucket)) {
            $key = $bucket->toDateString();
            $series[] = ['bucket' => $key, ...$values($byBucket->get($key) ?? new ReportStatsData)];
        }

        return $series;
    }

    /**
     * @return array{met: int, breached: int, compliance_pct: ?float}
     */
    private function compliance(ReportStatsData $row): array
    {
        return ['met' => $row->slaMetCount, 'breached' => $row->slaBreachedCount, 'compliance_pct' => $row->compliancePercent()];
    }
}
