<?php

declare(strict_types=1);

namespace App\Enums;

/** Channels that feed InboundMessageService. Only email has an adapter so far (docs/IMAP.md). */
enum InboundChannel: string
{
    case Email = 'email';

    public function ticketSource(): TicketSource
    {
        return match ($this) {
            self::Email => TicketSource::Email,
        };
    }
}
