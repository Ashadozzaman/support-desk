<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after commit by ProcessSlaBreachJob, once per ticket (idempotent
 * via sla_breach_notified_at — ARCHITECTURE.md §9).
 */
class SlaBreached
{
    use Dispatchable;

    public function __construct(public readonly Ticket $ticket) {}
}
