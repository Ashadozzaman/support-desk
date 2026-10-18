<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * Bucket size for report time series. Weeks start on Sunday, the first day
 * of the default Sun–Thu working week.
 */
enum ReportGranularity: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /**
     * First day of the bucket that contains $date — same rule as the SQL
     * bucket expression in ReportRepository, so zero-filled series line up
     * with the aggregated rows.
     */
    public function bucketStart(CarbonImmutable $date): CarbonImmutable
    {
        return match ($this) {
            self::Day => $date->startOfDay(),
            self::Week => $date->startOfDay()->subDays($date->dayOfWeek),
            self::Month => $date->startOfMonth(),
        };
    }

    public function next(CarbonImmutable $bucketStart): CarbonImmutable
    {
        return match ($this) {
            self::Day => $bucketStart->addDay(),
            self::Week => $bucketStart->addWeek(),
            self::Month => $bucketStart->addMonthNoOverflow(),
        };
    }
}
