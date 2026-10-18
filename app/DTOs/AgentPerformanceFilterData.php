<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;

/**
 * Window for the Agents list's "Completed" column (tickets resolved/closed
 * with `resolved_at` inside it). Both bounds are UTC instants — the request
 * already turned the admin's picked dates into business-timezone day
 * boundaries.
 */
readonly class AgentPerformanceFilterData
{
    public function __construct(
        public CarbonImmutable $completedFrom,
        public CarbonImmutable $completedTo,
    ) {}
}
