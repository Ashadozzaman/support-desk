<?php

declare(strict_types=1);

namespace App\Enums;

/** Same values as the Messages API roles, so history maps 1:1. */
enum ChatMessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
