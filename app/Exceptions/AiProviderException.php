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
    /** Kinds of failure an admin can fix in the provider account or .env (problem()). */
    public const MISSING_KEY = 'missing_key';

    public const INVALID_KEY = 'invalid_key';

    public const NO_CREDIT = 'no_credit';

    public const OTHER = 'other';

    // Provider error codes/types per kind: Anthropic (authentication_error,
    // billing_error) and OpenAI (invalid_api_key, insufficient_quota).
    private const INVALID_KEY_TYPES = ['authentication_error', 'invalid_api_key'];

    private const NO_CREDIT_TYPES = ['billing_error', 'insufficient_quota'];

    public function __construct(
        string $message,
        public readonly string $errorType,
        ?Throwable $previous = null,
        public readonly ?int $status = null,
    ) {
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
        return new self("AI provider returned HTTP {$status}.", $type ?? "http_{$status}", status: $status);
    }

    public static function invalidResponse(): self
    {
        return new self('The AI provider returned a response the app could not read.', 'invalid_response');
    }

    /**
     * What an admin would have to fix, for the staff-facing message
     * (DataAssistantService). Customers never see this.
     */
    public function problem(): string
    {
        return match (true) {
            $this->errorType === 'not_configured' => self::MISSING_KEY,
            $this->status === 401 || in_array($this->errorType, self::INVALID_KEY_TYPES, true) => self::INVALID_KEY,
            $this->status === 402 || in_array($this->errorType, self::NO_CREDIT_TYPES, true) => self::NO_CREDIT,
            default => self::OTHER,
        };
    }
}
