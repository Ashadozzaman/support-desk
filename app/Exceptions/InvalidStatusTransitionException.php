<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by TicketService::changeStatus() when TicketStatus::canTransitionTo()
 * rejects the move (ARCHITECTURE.md §5). Mapped to HTTP 422 in bootstrap/app.php.
 */
final class InvalidStatusTransitionException extends RuntimeException {}
