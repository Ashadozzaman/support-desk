<?php

declare(strict_types=1);

namespace App\Enums;

/** Where a ticket came in. Tickets created before Step 25a are all `web`. */
enum TicketSource: string
{
    case Web = 'web';
    case Email = 'email';
    case Chat = 'chat';
    case Import = 'import';
}
