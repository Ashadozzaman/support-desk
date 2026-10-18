<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\ReportGranularity;
use App\Enums\TicketPriority;
use Carbon\CarbonImmutable;

/**
 * Report filters (ENHANCEMENTS.md §1.3). `from`/`to` are inclusive
 * calendar days in the business timezone — the rollup's `stat_date` is
 * keyed by them. Live queries over `tickets` use fromUtc()/toUtc() instead.
 */
readonly class ReportFilterData
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ReportGranularity $granularity = ReportGranularity::Day,
        public ?TicketPriority $priority = null,
        public ?int $categoryId = null,
        public ?int $agentId = null,
    ) {}

    public function fromUtc(): CarbonImmutable
    {
        return $this->from->startOfDay()->utc();
    }

    /**
     * Exclusive bound: the start of the day after `to`.
     */
    public function toUtc(): CarbonImmutable
    {
        return $this->to->addDay()->startOfDay()->utc();
    }

    /**
     * Same filters narrowed to one agent — used to scope an agent to their
     * own row without trusting the agent id they asked for.
     */
    public function forAgent(int $agentId): self
    {
        return new self($this->from, $this->to, $this->granularity, $this->priority, $this->categoryId, $agentId);
    }

    /**
     * Same filters with a narrower window (percentiles are capped).
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to): self
    {
        return new self($from, $to, $this->granularity, $this->priority, $this->categoryId, $this->agentId);
    }

    /**
     * Stable cache-key fragment for these filters.
     */
    public function cacheKey(): string
    {
        return implode(':', [
            $this->from->toDateString(),
            $this->to->toDateString(),
            $this->granularity->value,
            $this->priority->value ?? '-',
            $this->categoryId ?? '-',
            $this->agentId ?? '-',
        ]);
    }
}
