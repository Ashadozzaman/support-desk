<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * AiGuard refused to make the call. Features catch this and fall back to the
 * non-AI path ("assistant unavailable — create a ticket"), so it is never a
 * user-facing error page.
 */
class AiUnavailableException extends RuntimeException
{
    public const DISABLED = 'disabled';

    public const RATE_LIMITED = 'rate_limited';

    public const BUDGET_EXCEEDED = 'budget_exceeded';

    public const CIRCUIT_OPEN = 'circuit_open';

    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function disabled(): self
    {
        return new self('The AI assistant is turned off.', self::DISABLED);
    }

    public static function rateLimited(): self
    {
        return new self('Too many AI requests — try again shortly.', self::RATE_LIMITED);
    }

    public static function budgetExceeded(): self
    {
        return new self("Today's AI token budget is used up.", self::BUDGET_EXCEEDED);
    }

    public static function circuitOpen(): self
    {
        return new self('The AI provider is failing; calls are paused for a few minutes.', self::CIRCUIT_OPEN);
    }
}
