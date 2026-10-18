<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after commit by ProcessSlaWarningJob, once per ticket (idempotent
 * via sla_warning_notified_at — ARCHITECTURE.md §9).
 */
class SlaWarningTriggered
{
    use Dispatchable;

    public function __construct(public readonly Ticket $ticket) {}
}
