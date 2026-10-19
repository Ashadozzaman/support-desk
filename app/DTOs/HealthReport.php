<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;

/**
 * Output of SystemHealthService::run(). `healthy` drives the /health status
 * code: only failed/crashed checks make it false — a warning (disk filling
 * up, a few failed jobs) is shown but shouldn't pull the app out of a load
 * balancer.
 */
readonly class HealthReport
{
    /**
     * @param  list<array{name: string, label: string, status: string, summary: string, message: string}>  $checks
     */
    public function __construct(
        public array $checks,
        public bool $healthy,
        public CarbonImmutable $checkedAt,
    ) {}

    /**
     * @return array{healthy: bool, checked_at: string, checks: list<array{name: string, label: string, status: string, summary: string, message: string}>}
     */
    public function toArray(): array
    {
        return [
            'healthy' => $this->healthy,
            'checked_at' => $this->checkedAt->toIso8601String(),
            'checks' => $this->checks,
        ];
    }
}
