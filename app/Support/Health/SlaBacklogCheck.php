<?php

declare(strict_types=1);

namespace App\Support\Health;

use App\Models\Ticket;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * The most common silent failure in an SLA system: the scheduler or the
 * `sla` worker stops, deadlines pass, and nobody is told. sla:scan runs
 * every minute, so an active ticket past due for more than a few minutes
 * without its breach flag means that pipeline is broken (ENHANCEMENTS.md §2.2).
 *
 * Only tickets that fell due in the last `lookbackHours` are examined. An
 * unbounded `sla_due_at <= now - 5min` had to walk every overdue active
 * ticket (all already flagged — ~350k on the 1M-ticket dev DB, 5.5 s). A
 * stalled pipeline shows up in the newest due tickets within minutes, so the
 * window loses nothing and keeps the (status, sla_due_at) range small.
 */
class SlaBacklogCheck extends Check
{
    protected int $graceMinutes = 5;

    protected int $lookbackHours = 24;

    public function graceMinutes(int $minutes): self
    {
        $this->graceMinutes = $minutes;

        return $this;
    }

    public function run(): Result
    {
        $stuck = Ticket::query()
            ->active()
            ->whereBetween('sla_due_at', [now()->subHours($this->lookbackHours), now()->subMinutes($this->graceMinutes)])
            ->whereNull('sla_breach_notified_at')
            ->exists();

        $result = Result::make()->meta(['grace_minutes' => $this->graceMinutes]);

        return $stuck
            ? $result->failed("Tickets are past their SLA for over {$this->graceMinutes} minutes without being escalated — is the scheduler / sla worker running?")->shortSummary('Escalation stalled')
            : $result->ok()->shortSummary('Up to date');
    }
}
