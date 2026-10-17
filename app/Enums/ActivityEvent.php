<?php

declare(strict_types=1);

namespace App\Enums;

enum ActivityEvent: string
{
    case TicketCreated = 'ticket.created';
    case TicketUpdated = 'ticket.updated';
    case TicketAssigned = 'ticket.assigned';
    case TicketReassigned = 'ticket.reassigned';
    case PriorityChanged = 'priority.changed';
    case StatusChanged = 'status.changed';
    case CategoryChanged = 'category.changed';
    case ReplyAdded = 'reply.added';
    case NoteAdded = 'note.added';
    case AttachmentAdded = 'attachment.added';
    case SlaWarning = 'sla.warning';
    case SlaBreached = 'sla.breached';
    case TicketEscalated = 'ticket.escalated';
    case TicketImported = 'ticket.imported';
    case TicketReopened = 'ticket.reopened';

    /**
     * Events a customer must never see (ARCHITECTURE.md §15): internal
     * notes, escalation, and SLA internals.
     *
     * @return array<int, self>
     */
    public static function staffOnly(): array
    {
        return [self::NoteAdded, self::TicketEscalated, self::SlaWarning, self::SlaBreached];
    }

    public function isCustomerVisible(): bool
    {
        return ! in_array($this, self::staffOnly(), true);
    }
}
