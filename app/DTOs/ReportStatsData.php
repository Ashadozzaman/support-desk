<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * One row of summed ticket_daily_stats measures, optionally grouped by a
 * bucket / priority / agent / category (whichever the query grouped by;
 * the others stay null). Agent/category 0 = unassigned / no category.
 */
readonly class ReportStatsData
{
    public function __construct(
        public int $createdCount = 0,
        public int $resolvedCount = 0,
        public int $slaMetCount = 0,
        public int $slaBreachedCount = 0,
        public int $sumResolutionMinutes = 0,
        public int $respondedCount = 0,
        public int $sumFirstResponseMinutes = 0,
        public int $escalatedCount = 0,
        public int $reopenedCount = 0,
        public ?string $bucket = null,
        public ?string $priority = null,
        public ?int $agentId = null,
        public ?int $categoryId = null,
    ) {}

    public function avgResolutionMinutes(): ?int
    {
        return $this->resolvedCount === 0 ? null : (int) round($this->sumResolutionMinutes / $this->resolvedCount);
    }

    public function avgFirstResponseMinutes(): ?int
    {
        return $this->respondedCount === 0 ? null : (int) round($this->sumFirstResponseMinutes / $this->respondedCount);
    }

    /**
     * met / (met + breached); resolved tickets without an SLA count in neither.
     */
    public function compliancePercent(): ?float
    {
        return self::percent($this->slaMetCount, $this->slaMetCount + $this->slaBreachedCount);
    }

    public static function percent(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : round(100 * $part / $whole, 1);
    }
}
