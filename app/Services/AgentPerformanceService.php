<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\AgentRepositoryInterface;
use App\DTOs\AgentMonthFilterData;
use App\DTOs\AgentPerformanceFilterData;
use App\Models\User;
use App\Support\TrendSeries;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AgentPerformanceService
{
    private const TREND_DAYS = 30;

    public function __construct(private readonly AgentRepositoryInterface $agents) {}

    /**
     * Not cached: per page it's two index range scans (~0.5s at ~1M
     * tickets, ARCHITECTURE.md §14.1), and the result varies by page and
     * date window, so a single warmed key no longer fits.
     */
    public function paginate(AgentPerformanceFilterData $filters, int $perPage): LengthAwarePaginator
    {
        return $this->agents->paginatePerformance($filters, $perPage);
    }

    /**
     * Day-by-day assigned/completed counts for one business-timezone month,
     * zero-filled so every day up to `$month->end` gets a row. Shared by
     * the detail page and its CSV export so the two can never disagree.
     *
     * @return array{
     *     month: string,
     *     days: list<array{date: string, assigned: int, completed: int}>,
     *     totals: array{assigned: int, completed: int},
     * }
     */
    public function daily(User $agent, AgentMonthFilterData $month): array
    {
        $assigned = $this->agents->assignedPerDay($agent->id, $month->start, $month->end);
        $completed = $this->agents->completedPerDay($agent->id, $month->start, $month->end);

        $days = [];
        for ($day = $month->start; $day->lte($month->end); $day = $day->addDay()) {
            $date = $day->toDateString();
            $days[] = [
                'date' => $date,
                'assigned' => $assigned->get($date, 0),
                'completed' => $completed->get($date, 0),
            ];
        }

        return [
            'month' => $month->label(),
            'days' => $days,
            'totals' => [
                'assigned' => array_sum(array_column($days, 'assigned')),
                'completed' => array_sum(array_column($days, 'completed')),
            ],
        ];
    }

    /**
     * @return array{statusBreakdown: array<string, int>, completedTrend: list<array{day: string, count: int}>}
     */
    public function detail(User $agent): array
    {
        $byDay = $this->agents->ticketsCompletedPerDay($agent->id, self::TREND_DAYS)->keyBy('day');

        return [
            'statusBreakdown' => $this->agents->statusBreakdown($agent->id),
            'completedTrend' => TrendSeries::zeroFilled($byDay, self::TREND_DAYS),
        ];
    }
}
