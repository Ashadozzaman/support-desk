<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Ticket;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after commit by ProcessSlaWarningJob, once per ticket (idempotent
 * via sla_warning_notified_at — ARCHITECTURE.md §9). Broadcasts to admins
 * (`staff.alerts`) and the ticket's staff channel only — SLA internals are
 * never customer-visible (CLAUDE.md rule 6 extends to SLA/escalation data).
 */
class SlaWarningTriggered implements ShouldBroadcast
{
    use Dispatchable;

    public string $broadcastQueue = 'broadcasts';

    public function __construct(public readonly Ticket $ticket) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('staff.alerts'),
            new PrivateChannel("tickets.{$this->ticket->ticket_number}.staff"),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'ticket_number' => $this->ticket->ticket_number,
        ];
    }
}
