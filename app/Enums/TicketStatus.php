<?php

declare(strict_types=1);

namespace App\Enums;

enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case WaitingForCustomer = 'waiting_for_customer';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /**
     * Valid forward transitions for the ticket lifecycle (ARCHITECTURE.md §5).
     * A customer reply reopens a resolved ticket, so Resolved -> Open is allowed.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Open => in_array($target, [self::InProgress, self::Resolved], true),
            self::InProgress => in_array($target, [self::WaitingForCustomer, self::Resolved], true),
            self::WaitingForCustomer => in_array($target, [self::InProgress, self::Resolved], true),
            self::Resolved => in_array($target, [self::Closed, self::Open], true),
            self::Closed => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In progress',
            self::WaitingForCustomer => 'Waiting for customer',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }
}
