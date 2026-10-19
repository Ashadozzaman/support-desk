<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\ActivityEvent;
use App\Enums\AuditEvent;
use Carbon\CarbonImmutable;

/**
 * Filters for the admin audit-log page. Dates are UTC instants — the
 * request already turned the picked business-timezone days into bounds.
 * `ticketEvent` is set only for the Tickets log (ticket_activities).
 */
readonly class AuditLogFilterData
{
    public function __construct(
        public ?string $logName,
        public ?AuditEvent $event,
        public ?ActivityEvent $ticketEvent,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    /** Only ticket_activities (Tickets log, or a ticket event picked). */
    public function showsOnlyTickets(): bool
    {
        return $this->logName === AuditEvent::LOG_TICKETS || $this->ticketEvent !== null;
    }

    /** Only activity_log (Sign-in or Admin log, or one of their events picked). */
    public function showsOnlyAuditLog(): bool
    {
        return ! $this->showsOnlyTickets() && ($this->logName !== null || $this->event !== null);
    }
}
