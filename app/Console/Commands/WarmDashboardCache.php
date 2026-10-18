<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\WarmDashboardCacheJob;
use Illuminate\Console\Command;

/**
 * Scheduled sub-minute (QUEUE_SCHEDULER_REALTIME.md §3) at roughly half of
 * `support.dashboard.cache_ttl` (default 60s) so the global dashboard
 * aggregate is rebuilt before its TTL expires — admin requests then always
 * read a warm `Cache::remember` instead of occasionally paying the cold
 * cost of the full-table aggregate.
 */
class WarmDashboardCache extends Command
{
    protected $signature = 'dashboard:warm-cache';

    protected $description = 'Refresh the cached global dashboard aggregate before it expires.';

    public function handle(): int
    {
        WarmDashboardCacheJob::dispatch();

        return self::SUCCESS;
    }
}
