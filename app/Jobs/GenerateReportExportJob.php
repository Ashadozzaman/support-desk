<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ReportExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes a large ticket export to the private disk (ENHANCEMENTS.md §1.4).
 * Own `exports` queue so a big export never delays SLA or notification jobs.
 * tries = 1: a retry would rewrite the whole file anyway, so a failure is
 * recorded (failed()) and the user can simply request it again.
 * ReportExportService::generate() is idempotent for a completed export.
 */
class GenerateReportExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $exportId)
    {
        $this->queue = 'exports';
    }

    public function handle(ReportExportService $exports): void
    {
        $exports->generate($this->exportId);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('report.export_failed', ['export_id' => $this->exportId, 'exception' => $exception ? $exception::class : null]);

        app(ReportExportService::class)->markFailed($this->exportId);
    }
}
