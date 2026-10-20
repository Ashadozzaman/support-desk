<?php

declare(strict_types=1);

namespace App\Enums;

enum InboundMessageStatus: string
{
    case TicketCreated = 'ticket_created';
    case ReplyAdded = 'reply_added';
    case Skipped = 'skipped';
}
