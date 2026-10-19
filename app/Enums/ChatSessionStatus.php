<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Active = the customer's current conversation (at most one per user).
 * HandedOff = turned into a ticket. Closed = the customer started over
 * without needing a ticket — what the AI report counts as "deflected".
 */
enum ChatSessionStatus: string
{
    case Active = 'active';
    case HandedOff = 'handed_off';
    case Closed = 'closed';
}
