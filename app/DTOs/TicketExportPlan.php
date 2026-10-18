<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\ReportExport;
use Illuminate\Support\LazyCollection;

/**
 * What ReportExportService decided for a ticket export: stream it now
 * ($rows), or it was queued ($queued) and the user will be notified.
 * Exactly one of the two is set.
 */
readonly class TicketExportPlan
{
    /**
     * @param  LazyCollection<int, array<int, string|int|float|null>>|null  $rows  data rows, header excluded
     */
    public function __construct(
        public ?LazyCollection $rows = null,
        public ?ReportExport $queued = null,
    ) {}
}
