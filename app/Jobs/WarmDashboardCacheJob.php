<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\DashboardService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatched every 30s by `dashboard:warm-cache` (QUEUE_SCHEDULER_REALTIME.md
 * §1 pattern: scheduler decides *when*, queue does the *heavy* work so the
 * scheduler process is never blocked for the ~5-10s this aggregate takes).
 * Rebuilding twice in a row is harmless — it just overwrites the same cache
 * key with an equally-fresh value — so `ShouldBeUnique` exists only to stop
 * jobs piling up if the queue worker falls behind, not for correctness.
 */
class WarmDashboardCacheJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 30;

    public function uniqueId(): string
    {
        return 'dashboard-global';
    }

    public function handle(DashboardService $dashboard): void
    {
        $dashboard->refreshGlobalCache();
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Dashboard cache warm failed', ['error' => $exception->getMessage()]);
    }
}
