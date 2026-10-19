<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The provider call itself failed (not configured, network, HTTP error,
 * unreadable response). Counts towards AiGuard's circuit breaker. The
 * message never includes the request or response body.
 */
class AiProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorType, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function notConfigured(): self
    {
        return new self('No API key is configured for the AI provider.', 'not_configured');
    }

    public static function connection(Throwable $previous): self
    {
        return new self('Could not reach the AI provider.', 'connection_error', $previous);
    }

    /**
     * @param  string|null  $type  The provider's own error type, e.g. "rate_limit_error".
     */
    public static function http(int $status, ?string $type): self
    {
        return new self("AI provider returned HTTP {$status}.", $type ?? "http_{$status}");
    }

    public static function invalidResponse(): self
    {
        return new self('The AI provider returned a response the app could not read.', 'invalid_response');
    }
}
