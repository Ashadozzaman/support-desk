<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Hand-off asked for without an open conversation (none started, or it has
 * already ended). Rendered as a 422 field error on `chat` (bootstrap/app.php).
 */
class ChatNotActiveException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('There is no open conversation to hand over. Ask a question first, or create a ticket.');
    }
}
