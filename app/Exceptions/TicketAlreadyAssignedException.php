<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by TicketAssignmentService::claim() when the atomic conditional
 * UPDATE affects 0 rows — another agent won the race first (ARCHITECTURE.md
 * §7). Mapped to HTTP 409 in bootstrap/app.php.
 */
final class TicketAlreadyAssignedException extends RuntimeException {}
