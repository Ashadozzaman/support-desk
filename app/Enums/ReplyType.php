<?php

declare(strict_types=1);

namespace App\Enums;

enum ReplyType: string
{
    case Reply = 'reply';
    case InternalNote = 'internal_note';
}
