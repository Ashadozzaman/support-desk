<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Collection;

class TrendSeries
{
    /**
     * Turns a sparse "day => count" collection (only days with activity)
     * into a continuous `$days`-point series ending today, zero-filling the
     * gaps — shared by DashboardService::trend() and
     * AgentPerformanceService so a trend chart never skips a day just
     * because nothing happened on it.
     *
     * @param  Collection<string, array{day: string, count: int}>  $byDay  keyed by day string
     * @return list<array{day: string, count: int}>
     */
    public static function zeroFilled(Collection $byDay, int $days): array
    {
        return collect(range($days - 1, 0))
            ->map(fn (int $daysAgo) => now()->subDays($daysAgo)->toDateString())
            ->map(fn (string $day) => [
                'day' => $day,
                'count' => $byDay->get($day)['count'] ?? 0,
            ])
            ->values()
            ->all();
    }
}
