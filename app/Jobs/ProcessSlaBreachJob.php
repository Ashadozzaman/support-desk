<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ActivityEvent;
use App\Events\SlaBreached as SlaBreachedEvent;
use App\Models\Ticket;
use App\Services\ActivityLogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatched from sla:scan (queue `sla`, QUEUE_SCHEDULER_REALTIME.md §1/§2).
 * Idempotent via sla_breach_notified_at, same pattern as
 * ProcessSlaWarningJob. A breach also escalates the ticket (ARCHITECTURE.md
 * §9): bumps escalation_level and writes a second activity row.
 */
class ProcessSlaBreachJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public int $timeout = 60;

    public function __construct(public readonly int $ticketId)
    {
        // Queueable::$queue is untyped — redeclaring it as `public string`
        // here causes a PHP fatal (incompatible trait property composition),
        // so it's set at runtime instead.
        $this->queue = 'sla';
    }

    public function handle(ActivityLogService $activity): void
    {
        DB::transaction(function () use ($activity) {
            $affected = Ticket::query()
                ->whereKey($this->ticketId)
                ->whereNull('sla_breach_notified_at')
                ->update([
                    'sla_breach_notified_at' => now(),
                    'escalated_at' => now(),
                    'escalation_level' => DB::raw('escalation_level + 1'),
                ]);

            if ($affected === 0) {
                return;
            }

            $ticket = Ticket::findOrFail($this->ticketId);

            $activity->log($ticket, ActivityEvent::SlaBreached, null, [], ['sla_due_at' => $ticket->sla_due_at?->toIso8601String()]);
            $activity->log($ticket, ActivityEvent::TicketEscalated, null, [], ['escalation_level' => $ticket->escalation_level]);

            DB::afterCommit(fn () => SlaBreachedEvent::dispatch($ticket));
        });
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SLA breach job failed', [
            'ticket_id' => $this->ticketId,
            'error' => $exception->getMessage(),
        ]);
    }
}
